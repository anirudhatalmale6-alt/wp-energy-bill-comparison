<?php
/**
 * Getting text out of an uploaded PDF.
 *
 * Three routes are tried in order, because shared hosting varies so much:
 *
 *   1. `pdftotext -layout`, when the host allows shell commands. Best quality,
 *      and the layout mode keeps the columns of a rate table together.
 *   2. A small built-in parser that inflates the content streams and pulls out
 *      the text-showing operators. Works on the ordinary text PDFs that
 *      retailer billing systems produce.
 *   3. Nothing. A scanned bill has no text layer at all, in which case the
 *      caller falls back to sending the PDF itself to the AI extractor.
 */

defined( 'ABSPATH' ) || exit;

class AEC_PDF {

	/** @var array Cache so a file is only read off disk once per request. */
	protected static $cache = array();

	/**
	 * Read a file, remembering the bytes for the rest of the request.
	 *
	 * @param string $path
	 * @return string
	 */
	protected static function read( $path ) {
		$key = $path . '|' . ( is_readable( $path ) ? (string) filesize( $path ) : '0' );

		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$raw = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

		// One bill at a time; never hold more than one document in memory.
		self::$cache = array( $key => $raw );

		return $raw;
	}

	/**
	 * Is the uploaded file really a PDF?
	 *
	 * @param string $path
	 * @return bool
	 */
	public static function is_pdf( $path ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}
		$head = fread( $handle, 1024 );
		fclose( $handle );
		// Some generators put junk before the header, so search the first block
		// rather than requiring it at offset zero.
		return is_string( $head ) && false !== strpos( $head, '%PDF-' );
	}

	/**
	 * Is the document encrypted? Those cannot be read without the password.
	 *
	 * @param string $path
	 * @return bool
	 */
	public static function is_encrypted( $path ) {
		$raw = self::read( $path );
		return ( false !== strpos( $raw, '/Encrypt' ) );
	}

	/**
	 * Rough page count.
	 *
	 * @param string $path
	 * @return int
	 */
	public static function page_count( $path ) {
		$raw = self::read( $path );
		if ( preg_match( '/\/Type\s*\/Pages[^>]*?\/Count\s+(\d+)/s', $raw, $m ) ) {
			return max( 1, (int) $m[1] );
		}
		$count = preg_match_all( '/\/Type\s*\/Page[^s]/', $raw );
		return max( 1, (int) $count );
	}

	/**
	 * Extract text.
	 *
	 * @param string $path
	 * @return array text, method, quality (0-1)
	 */
	public static function extract_text( $path ) {
		$binary = self::pdftotext_binary();

		if ( $binary ) {
			$text = self::via_pdftotext( $binary, $path );
			if ( self::looks_like_text( $text ) ) {
				return array(
					'text'    => $text,
					'method'  => 'pdftotext',
					'quality' => self::quality( $text ),
				);
			}
		}

		$text = self::via_parser( $path );
		if ( self::looks_like_text( $text ) ) {
			return array(
				'text'    => $text,
				'method'  => 'builtin',
				'quality' => self::quality( $text ),
			);
		}

		return array(
			'text'    => '',
			'method'  => 'none',
			'quality' => 0.0,
		);
	}

	/**
	 * Locate a usable pdftotext, if the host permits running one at all.
	 *
	 * @return string|false
	 */
	public static function pdftotext_binary() {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		foreach ( array( 'shell_exec', 'exec', 'proc_open', 'popen' ) as $fn ) {
			if ( in_array( $fn, $disabled, true ) ) {
				return false;
			}
		}

		foreach ( array( '/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		$found = shell_exec( 'command -v pdftotext 2>/dev/null' );
		$found = is_string( $found ) ? trim( $found ) : '';

		return ( '' !== $found && is_executable( $found ) ) ? $found : false;
	}

	/**
	 * @param string $binary
	 * @param string $path
	 * @return string
	 */
	protected static function via_pdftotext( $binary, $path ) {
		$out = self::temp_path( $path );
		if ( ! $out ) {
			return '';
		}

		$cmd = escapeshellcmd( $binary ) . ' -layout -enc UTF-8 -q '
			. escapeshellarg( $path ) . ' ' . escapeshellarg( $out ) . ' 2>/dev/null';

		// The return value is ignored on purpose: pdftotext exits non-zero on
		// some damaged-but-readable files while still writing usable text, so
		// the output file is what gets judged.
		shell_exec( $cmd );

		$text = is_readable( $out ) ? (string) file_get_contents( $out ) : '';
		@unlink( $out );

		return self::tidy( $text );
	}

	/**
	 * Somewhere to write pdftotext's output.
	 *
	 * Deliberately not wp_tempnam(): that lives in wp-admin/includes/file.php,
	 * which is not loaded on the front end, and extraction runs on a front-end
	 * REST request. Calling it there is a fatal, not a notice.
	 *
	 * The scratch file is written beside the PDF, inside the plugin's own
	 * private directory, so an extracted bill never passes through a shared
	 * temp directory.
	 *
	 * @param string $source Path of the PDF being read.
	 * @return string|false
	 */
	protected static function temp_path( $source ) {
		$candidates = array( dirname( $source ) );

		if ( function_exists( 'get_temp_dir' ) ) {
			$candidates[] = untrailingslashit( get_temp_dir() );
		}
		$candidates[] = sys_get_temp_dir();

		$suffix = function_exists( 'wp_generate_password' )
			? wp_generate_password( 12, false, false )
			: bin2hex( random_bytes( 6 ) );

		foreach ( $candidates as $dir ) {
			if ( ! $dir || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
				continue;
			}
			return trailingslashit( $dir ) . 'aec-text-' . $suffix . '.txt';
		}

		return false;
	}

	/**
	 * Built-in content-stream parser.
	 *
	 * @param string $path
	 * @return string
	 */
	protected static function via_parser( $path ) {
		$raw = self::read( $path );
		if ( '' === $raw ) {
			return '';
		}
		if ( false !== strpos( $raw, '/Encrypt' ) ) {
			return '';
		}

		$pieces = array();
		$offset = 0;

		while ( true ) {
			$start = strpos( $raw, 'stream', $offset );
			if ( false === $start ) {
				break;
			}

			$cursor = $start + 6;
			// The keyword is followed by CRLF or LF.
			if ( isset( $raw[ $cursor ] ) && "\r" === $raw[ $cursor ] ) {
				$cursor++;
			}
			if ( isset( $raw[ $cursor ] ) && "\n" === $raw[ $cursor ] ) {
				$cursor++;
			}

			$end = strpos( $raw, 'endstream', $cursor );
			if ( false === $end ) {
				break;
			}

			$data   = substr( $raw, $cursor, $end - $cursor );
			$offset = $end + 9;

			// The stream's own dictionary says how it was encoded. Guessing
			// instead is how an ASCII85 + Flate stream reads as empty.
			$dict    = substr( $raw, max( 0, $start - 900 ), min( 900, $start - max( 0, $start - 900 ) ) );
			$filters = self::stream_filters( $dict );

			$text = self::decode_stream( $data, $filters );
			if ( '' === $text ) {
				continue;
			}
			// Only content streams carry text operators; images and fonts do not.
			if ( ! preg_match( '/(Tj|TJ)\s/', $text ) ) {
				continue;
			}

			$pieces[] = self::operators_to_text( $text );
		}

		return self::tidy( implode( "\n", $pieces ) );
	}

	/**
	 * Read the /Filter entry out of a stream's dictionary.
	 *
	 * @param string $dict The bytes immediately before the `stream` keyword.
	 * @return array Filter names in the order they must be undone.
	 */
	protected static function stream_filters( $dict ) {
		// Take the last /Filter before the keyword: that is this stream's own.
		if ( ! preg_match_all( '/\/Filter\s*(\[[^\]]*\]|\/[A-Za-z0-9]+)/', $dict, $all ) ) {
			return array();
		}

		$value = end( $all[1] );

		preg_match_all( '/\/([A-Za-z0-9]+)/', $value, $names );

		return isset( $names[1] ) ? $names[1] : array();
	}

	/**
	 * Undo a stream's filter chain.
	 *
	 * Billing systems in the wild use FlateDecode on its own, and also
	 * ASCII85Decode wrapped around FlateDecode. Applying only the inner one
	 * leaves the stream looking like noise, which reads as "no text layer".
	 *
	 * @param string $data
	 * @param array  $filters
	 * @return string Empty when the chain cannot be undone or is an image.
	 */
	protected static function decode_stream( $data, $filters ) {
		if ( '' === trim( $data ) ) {
			return '';
		}

		// Image and font payloads carry no text to read.
		$binary_only = array( 'DCTDecode', 'JPXDecode', 'JBIG2Decode', 'CCITTFaxDecode' );
		foreach ( $filters as $filter ) {
			if ( in_array( $filter, $binary_only, true ) ) {
				return '';
			}
		}

		if ( $filters ) {
			foreach ( $filters as $filter ) {
				switch ( $filter ) {
					case 'ASCII85Decode':
					case 'A85':
						$data = self::ascii85_decode( $data );
						break;
					case 'ASCIIHexDecode':
					case 'AHx':
						$data = self::asciihex_decode( $data );
						break;
					case 'FlateDecode':
					case 'Fl':
						$data = self::inflate( $data );
						break;
					case 'RunLengthDecode':
					case 'RL':
						$data = self::runlength_decode( $data );
						break;
					case 'Crypt':
						// Encrypted documents are rejected earlier.
						return '';
					default:
						// LZWDecode and anything else exotic: leave it to the
						// AI reader rather than returning mangled text.
						return '';
				}

				if ( '' === $data ) {
					return '';
				}
			}

			return $data;
		}

		// No /Filter found. Either the stream is already plain, or the
		// dictionary was out of reach, so try the common encodings.
		if ( preg_match( '/(BT|Tj|TJ)\s/', substr( $data, 0, 2048 ) ) ) {
			return $data;
		}

		$out = self::inflate( $data );
		if ( '' !== $out ) {
			return $out;
		}

		return self::inflate( self::ascii85_decode( $data ) );
	}

	/**
	 * zlib or raw deflate.
	 *
	 * @param string $data
	 * @return string
	 */
	protected static function inflate( $data ) {
		if ( '' === $data ) {
			return '';
		}

		if ( function_exists( 'gzuncompress' ) ) {
			$out = @gzuncompress( $data );
			if ( false !== $out && '' !== $out ) {
				return $out;
			}
		}

		if ( function_exists( 'gzinflate' ) ) {
			// Raw deflate, and deflate with the two-byte zlib header shaved off.
			$out = @gzinflate( $data );
			if ( false !== $out && '' !== $out ) {
				return $out;
			}
			$out = @gzinflate( substr( $data, 2 ) );
			if ( false !== $out && '' !== $out ) {
				return $out;
			}
		}

		return '';
	}

	/**
	 * Adobe ASCII85.
	 *
	 * @param string $data
	 * @return string
	 */
	protected static function ascii85_decode( $data ) {
		$data = preg_replace( '/\s+/', '', $data );

		if ( 0 === strpos( $data, '<~' ) ) {
			$data = substr( $data, 2 );
		}

		$end = strpos( $data, '~>' );
		if ( false !== $end ) {
			$data = substr( $data, 0, $end );
		}

		$out   = '';
		$group = array();
		$len   = strlen( $data );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $data[ $i ];

			if ( 'z' === $char && ! $group ) {
				$out .= "\0\0\0\0";
				continue;
			}

			$value = ord( $char ) - 33;
			if ( $value < 0 || $value > 84 ) {
				// Not ASCII85 after all.
				return '';
			}

			$group[] = $value;

			if ( 5 === count( $group ) ) {
				$sum = 0;
				foreach ( $group as $digit ) {
					$sum = ( $sum * 85 ) + $digit;
				}
				$out  .= pack( 'N', $sum );
				$group = array();
			}
		}

		if ( $group ) {
			$count = count( $group );
			// A trailing partial group is padded with the highest digit.
			for ( $i = $count; $i < 5; $i++ ) {
				$group[] = 84;
			}
			$sum = 0;
			foreach ( $group as $digit ) {
				$sum = ( $sum * 85 ) + $digit;
			}
			$out .= substr( pack( 'N', $sum ), 0, $count - 1 );
		}

		return $out;
	}

	/**
	 * ASCIIHexDecode.
	 *
	 * @param string $data
	 * @return string
	 */
	protected static function asciihex_decode( $data ) {
		$end = strpos( $data, '>' );
		if ( false !== $end ) {
			$data = substr( $data, 0, $end );
		}

		$hex = preg_replace( '/[^0-9A-Fa-f]/', '', $data );
		if ( strlen( $hex ) % 2 ) {
			$hex .= '0';
		}

		$out = @hex2bin( $hex );

		return is_string( $out ) ? $out : '';
	}

	/**
	 * RunLengthDecode.
	 *
	 * @param string $data
	 * @return string
	 */
	protected static function runlength_decode( $data ) {
		$out = '';
		$i   = 0;
		$len = strlen( $data );

		while ( $i < $len ) {
			$length = ord( $data[ $i ] );
			$i++;

			if ( 128 === $length ) {
				break;
			}

			if ( $length < 128 ) {
				$out .= substr( $data, $i, $length + 1 );
				$i   += $length + 1;
				continue;
			}

			if ( $i < $len ) {
				$out .= str_repeat( $data[ $i ], 257 - $length );
				$i++;
			}
		}

		return $out;
	}

	/**
	 * Turn a content stream into readable lines.
	 *
	 * @param string $stream
	 * @return string
	 */
	protected static function operators_to_text( $stream ) {
		$out = '';
		$len = strlen( $stream );
		$i   = 0;

		// Collected string literals in document order, with line breaks inserted
		// where the stream moves to a new text position.
		while ( $i < $len ) {
			$char = $stream[ $i ];

			if ( '(' === $char ) {
				$depth  = 1;
				$i++;
				$buffer = '';
				while ( $i < $len && $depth > 0 ) {
					$c = $stream[ $i ];
					if ( '\\' === $c && $i + 1 < $len ) {
						$next = $stream[ $i + 1 ];
						switch ( $next ) {
							case 'n':
								$buffer .= "\n";
								$i      += 2;
								continue 2;
							case 'r':
								$buffer .= "\r";
								$i      += 2;
								continue 2;
							case 't':
								$buffer .= "\t";
								$i      += 2;
								continue 2;
							case 'b':
							case 'f':
								$i += 2;
								continue 2;
							case '(':
							case ')':
							case '\\':
								$buffer .= $next;
								$i      += 2;
								continue 2;
							default:
								if ( preg_match( '/^[0-7]{1,3}/', substr( $stream, $i + 1, 3 ), $m ) ) {
									$buffer .= chr( octdec( $m[0] ) );
									$i      += 1 + strlen( $m[0] );
									continue 2;
								}
								$i += 2;
								continue 2;
						}
					}
					if ( '(' === $c ) {
						$depth++;
					} elseif ( ')' === $c ) {
						$depth--;
						if ( 0 === $depth ) {
							$i++;
							break;
						}
					}
					$buffer .= $c;
					$i++;
				}
				$out .= $buffer;
				continue;
			}

			if ( '<' === $char && isset( $stream[ $i + 1 ] ) && '<' !== $stream[ $i + 1 ] ) {
				// Hex string.
				$close = strpos( $stream, '>', $i );
				if ( false === $close ) {
					break;
				}
				$hex = preg_replace( '/[^0-9A-Fa-f]/', '', substr( $stream, $i + 1, $close - $i - 1 ) );
				if ( strlen( $hex ) % 2 ) {
					$hex .= '0';
				}
				$out .= @hex2bin( $hex );
				$i    = $close + 1;
				continue;
			}

			// Positioning and line-break operators become whitespace.
			if ( preg_match( '/^(T\*|Td|TD|TL|ET)[\s]/', substr( $stream, $i, 4 ) ) ) {
				$out .= "\n";
				$i   += 2;
				continue;
			}

			$i++;
		}

		return $out;
	}

	/**
	 * Normalise whitespace and encoding.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function tidy( $text ) {
		if ( '' === $text ) {
			return '';
		}

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		// Drop control characters but keep tabs and newlines.
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text );

		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}

		$text = preg_replace( '/[ \t]{2,}/', '  ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Does this look like real text rather than mis-decoded font bytes?
	 *
	 * @param string $text
	 * @return bool
	 */
	public static function looks_like_text( $text ) {
		return self::quality( $text ) >= 0.55 && strlen( $text ) >= 120;
	}

	/**
	 * Share of characters that are plausible bill text.
	 *
	 * @param string $text
	 * @return float 0-1
	 */
	public static function quality( $text ) {
		$len = strlen( $text );
		if ( $len < 1 ) {
			return 0.0;
		}
		$good = preg_match_all( '/[A-Za-z0-9 \.\,\$\%\/\-\:\(\)\n\t]/', $text );
		return $len > 0 ? min( 1.0, $good / $len ) : 0.0;
	}
}
