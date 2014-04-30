<?php

namespace Sbp
{

	class SbpException extends \Exception {}

	class Sbp
	{
		const COMMENT = '/* Generated By SBP */';
		const VALIDNAME = '[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*';
		const NUMBER = '(?:0[xbXB])?[0-9]*\.?[0-9]+(?:[eE](?:0[xbXB])?[0-9]*\.?[0-9]+)?';
		const VALIDVAR = '(?<!\$)\$+[^\$\n\r=]+([\[\{]((?>[^\[\{\]\}]+)|(?-2))*[\]\}])?(?![a-zA-Z0-9_\x7f-\xff\$\[\{])';
		const BRACES = '(?:\{((?>[^\{\}]+)|(?-2))*\})';
		const BRAKETS = '(?:\[((?>[^\[\]]+)|(?-2))*\])';
		const PARENTHESES = '(?:\(((?>[^\(\)]+)|(?-2))*\))';
		const CONSTNAME = '[A-Z_]+';
		const SUBST = '÷';
		const COMP = '`';
		const VALUE = 'µ';
		const CHAINER = '¤';
		const COMMENTS = '\/\/.*(?=\n)|\/\*(?:.|\n)*\*\/';
		const OPERATORS = '\|\||\&\&|or|and|xor|is|not|<>|lt|gt|<=|>=|\!==|===|\?\:';
		const PHP_WORDS = 'true|false|null|echo|print|static|yield|var|exit|as|case|default|clone|endswtch|endwhile|endfor|endforeach|callable|endif|enddeclare|final|finally|label|goto|const|global|namespace|instanceof|new|throw|include|require|include_once|require_once|use|exit|continue|return|break|extends|implements|abstract|public|protected|private|function|interface';
		const BLOKCS = 'if|else|elseif|try|catch|function|class|trait|switch|while|for|foreach|do';
		const MUST_CLOSE_BLOKCS = 'try|catch|function|class|trait|switch|interface';
		const IF_BLOKCS = 'if|elseif|catch|switch|while|for|foreach';
		const START = '((?:^|[\n;\{\}])(?:\/\/.*(?=\n)|\/\*(?:.|\n)*\*\/\s*)*\s*)';
		const ABSTRACT_SHORTCUTS = 'abstract|abst|abs|a';
		const BENCHMARK_END = -1;

		const SAME_DIR = 0x01;

		static protected $prod = false;
		static protected $destination = self::SAME_DIR;
		static protected $callbackWriteIn = null;
		static protected $lastParsedFile = null;
		static protected $plugins = array();
		static protected $validExpressionRegex = null;

		static public function prod($on = true)
		{
			static::$prod = !!$on;
		}

		static public function dev($off = true)
		{
			static::$prod = !$off;
		}

		static public function addPlugin($plugin, $from, $to = null)
		{
			if(!is_null($to))
			{
				$from = array( $from => $to );
			}
			static::$plugins[$plugin] = $from;
		}

		static public function removePlugin($plugin)
		{
			unset(static::$plugins[$plugin]);
		}

		static public function benchmarkEnd()
		{
			static::benchmark(static::BENCHMARK_END);
		}

		static protected function getBenchmarkHtml(&$list)
		{
			$previous = null;
			$times = array_keys($list);
			$len = max(0, min(2, max(array_map(function ($key)
			{
				$key = explode('.', $key);
				return strlen(end($key)) - 3;
			}, $times))));
			$list[strval(microtime(true))] = "End benchmark";
			$ul = '';
			foreach($list as $time => $title)
			{
				$ul .= '<li>' . (is_null($previous) ? '' : '<b>' . number_format(($time - $previous) * 1000, $len) . 'ms</b>') . $title . '</li>';
				$previous = $time;
			}
			return '<!doctype html>
				<html lang="en">
					<head>
						<meta charset="UTF-8" />
						<title>SBP - Benchmark</title>
						<style type="text/css">
						body
						{
							font-family: sans-serif;
						}
						li
						{
							margin: 40px;
							position: relative;
						}
						li b
						{
							font-weight: bold;
							position: absolute;
							top: -30px;
							left: -8px;
						}
						</style>
					</head>
					<body>
						<h1>Benckmark</h1>
						<ul>' . $ul . '</ul>
						<p>All: <b>' . number_format((end($times) - reset($times)) * 1000, $len, ',', ' ') . 'ms</b></p>
						<h1>Code source</h1>
						<pre>' . htmlspecialchars(ob_get_clean()) . '</pre>
					</body>
				</html>';
		}

		static protected function recordBenchmark(&$list, $title)
		{
			$time = strval(microtime(true));
			if(empty($title))
			{
				$list = array($time => "Start benchmark");
				ob_start();
			}
			elseif(is_array($list))
			{
				if($title === static::BENCHMARK_END)
				{
					exit(static::getBenchmarkHtml($list));
				}
				else
				{
					$list[$time] = $title;
				}
			}
		}

		static public function benchmark($title = '')
		{
			static $list = null;
			return static::recordBenchmark($list, $title);
		}

		static public function writeIn($directory = self::SAME_DIR, $callback = null)
		{
			if($directory !== self::SAME_DIR)
			{
				$directory = rtrim($directory, '/\\');
				if( ! file_exists($directory))
				{
					throw new SbpException($directory . " : path not found");
				}
				if( ! is_writable($directory))
				{
					throw new SbpException($directory . " : persmission denied");
				}
				$directory .= DIRECTORY_SEPARATOR;
			}
			self::$destination = $directory;
			if( ! is_null($callback))
			{
				if( ! is_callable($callback))
				{
					throw new SbpException("Invalid callback");
				}
				self::$callbackWriteIn = $callback;
			}
		}

		static public function isSbp($file)
		{
			return (
				strpos($file, $k = ' '.self::COMMENT) !== false ||
				(
					substr($file, 0, 1) === '/' &&
					@file_exists($file) &&
					strpos(file_get_contents($file), $k) !== false
				)
			);
		}

		static public function parseClass($match)
		{
			list($all, $start, $class, $extend, $implement, $end) = $match;
			$class = trim($class);
			if(in_array(substr($all, 0, 1), str_split(',(+-/*&|'))
			|| in_array($class, array_merge(
				array('else', 'try', 'default:', 'echo', 'print', 'exit', 'continue', 'break', 'return', 'do'),
				explode('|', self::PHP_WORDS)
			)))
			{
				return $all;
			}
			$className = preg_replace('#^(?:'.self::ABSTRACT_SHORTCUTS.')\s+#', '', $class, -1, $isAbstract);
			$codeLine = $start.($isAbstract ? 'abstract ' : '').'class '.$className.
				(empty($extend) ? '' : ' extends '.trim($extend)).
				(empty($implement) ? '' : ' implements '.trim($implement)).
				' '.trim($end);
			return $codeLine.str_repeat("\n", substr_count($all, "\n") - substr_count($codeLine, "\n"));
		}

		static private function findLastBlock(&$line, $block = array())
		{
			$pos = false;
			if(empty($block))
			{
				$block = explode('|', self::BLOKCS);
			}
			if(!is_array($block))
			{
				$block = array($block);
			}
			foreach($block as $word)
			{
				if(preg_match('#(?<![a-zA-Z0-9$_])'.$word.'(?![a-zA-Z0-9_])#s', $line, $match, PREG_OFFSET_CAPTURE))
				{
					$p = $match[0][1] + 1;
					if($pos === false || $p > $pos)
					{
						$pos = $p;
					}
				}
			}
			return $pos;
		}

		static public function isBlock(&$line, &$grouped, $iRead = 0)
		{
			if(substr(rtrim($line), -1) === ';')
			{
				return false;
			}
			$find = self::findLastBlock($line);
			$pos = $find ?: 0;
			$ouvre = substr_count($line, '(', $pos);
			$ferme = substr_count($line, ')', $pos);
			if($ouvre > $ferme)
			{
				return false;
			}
			if($ouvre < $ferme)
			{
				$c = $ferme - $ouvre;
				$content = ' '.implode("\n", array_slice($grouped, 0, $iRead));
				while($c !== 0)
				{
					$ouvre = strrpos($content, '(') ?: 0;
					$ferme = strrpos($content, ')') ?: 0;
					if($ouvre === 0 && $ferme === 0)
					{
						return false;
					}
					if($ouvre > $ferme)
					{
						$c--;
						$content = substr($content, 0, $ouvre);
					}
					else
					{
						$c++;
						$content = substr($content, 0, $ferme);
					}
				}
				$content = substr($content, 1);
				$find = self::findLastBlock($content);
				$pos = $find ?: 0;
				return $find !== false && substr_count($content, '{', $pos) === 0;
			}
			return $find !== false && substr_count($line, '{', $pos) === 0;
		}

		static public function contentTab($match)
		{
			return $match[1].str_replace("\n", "\n".$match[1], $GLOBALS['sbpContentTab']);
		}

		static public function container($container, $file, $content, $basename = null, $name = null)
		{
			$basename = $basename ?: basename($file);
			$name = $name ?: preg_replace('#\..+$#', '', $basename);
			$camelCase = preg_replace_callback('#[-_]([a-z])#', function ($match) { return strtoupper($match[1]); }, $name);
			$replace = array(
				'{file}' => $file,
				'{basename}' => $basename,
				'{name}' => $name,
				'{camelCase}' => $camelCase,
				'{CamelCase}' => ucfirst($camelCase)
			);
			$GLOBALS['sbpContentTab'] = $content;
			$container = preg_replace_callback('#(\t*){content}#', array(get_class(), 'contentTab'), $container);
			unset($GLOBALS['sbpContentTab']);
			return str_replace(array_keys($replace), array_values($replace), $container);
		}

		static public function parseWithContainer($container, $file, $content, $basename = null, $name = null)
		{
			$content=self::container($container,$file,'/*sbp-container-end*/'.$content,$fin);
			$content=self::parse($content);
			$content=explode('/*sbp-container-end*/', $content, 2);
			$content[0]=strtr($content[0],"\r\n","  ");
			return implode('',$content);
		}

		static public function replaceString($match)
		{
			if(is_array($match))
			{
				$match = $match[0];
			}
			$id = count($GLOBALS['replaceStrings']);
			$GLOBALS['replaceStrings'][$id] = $match;
			if(strpos($match, '/') === 0)
			{
				$GLOBALS['commentStrings'][] = $id;
			}
			elseif(strpos($match, '?') === 0)
			{
				$GLOBALS['htmlCodes'][] = $id;
			}
			else
			{
				$GLOBALS['quotedStrings'][] = $id;
			}
			return self::COMP.self::SUBST.$id.self::SUBST.self::COMP;
		}

		static protected function stringRegex()
		{
			$antislash = preg_quote('\\');
			return '([\'"]).*(?<!'.$antislash.')(?:'.$antislash.$antislash.')*\\1';
		}

		static protected function validSubst($motif = '[0-9]+')
		{
			if($motif === '(?:)')
			{
				$motif = '(?:[^\S\s])';
			}
			return preg_quote(self::COMP.self::SUBST).$motif.preg_quote(self::SUBST.self::COMP);
		}

		static public function fileMatchnigLetter($file)
		{
			if(fileowner($file) === getmyuid())
			{
				return 'u';
			}
			if(filegroup($file) === getmygid())
			{
				return 'g';
			}
			return 'o';
		}

		static public function fileParse($from, $to = null)
		{
			if(is_null($to))
			{
				$to = $from;
			}
			if(!is_readable($from))
			{ 
				throw new SbpException($from." is not readable, try :\nchmod ".static::fileMatchnigLetter($from)."+r ".$from, 1);
				return false;
			}
			if(!is_writable($dir = dirname($to)))
			{ 
				throw new SbpException($dir." is not writable, try :\nchmod ".static::fileMatchnigLetter($dir)."+w ".$dir, 1);
				return false;
			}
			static::$lastParsedFile = $from;
			$writed = file_put_contents($to, self::parse(file_get_contents($from)));
			static::$lastParsedFile = null;
			return $writed;
		}

		static public function phpFile($file)
		{
			$callback = (is_null(static::$callbackWriteIn) ?
				'sha1' :
				static::$callbackWriteIn
			);
			return (self::$destination === self::SAME_DIR ?
				$file.'.php' :
				self::$destination.$callback($file).'.php'
			);
		}

		static public function fileExists($file, &$phpFile = null)
		{
			$file = preg_replace('#(\.sbp)?(\.php)?$#', '', $file);
			$sbpFile = $file.'.sbp.php';
			$callback = (is_null(static::$callbackWriteIn) ?
				'sha1' :
				static::$callbackWriteIn
			);
			$phpFile = static::phpFile($file);
			if(!file_exists($phpFile))
			{
				if(file_exists($sbpFile))
				{
					self::fileParse($sbpFile, $phpFile);
					return true;
				}
			}
			else
			{
				if(file_exists($sbpFile) && filemtime($sbpFile) > filemtime($phpFile))
				{
					self::fileParse($sbpFile, $phpFile);
				}
				return true;
			}
			return false;
		}

		static public function sbpFromFile($file)
		{
			if(preg_match('#/*:(.+):*/#U', file_get_contents($file), $match))
			{
				return $match[1];
			}
		}

		static public function includeFile($file)
		{
			if(static::$prod)
			{
				return include(static::phpFile(preg_replace('#(\.sbp)?(\.php)?$#', '', $file)));
			}
			if(!static::fileExists($file, $phpFile))
			{
				throw new SbpException($file." not found", 1);
				return false;
			}
			return include($phpFile);
		}

		static public function includeOnceFile($file)
		{
			if(static::$prod)
			{
				return include_once(static::phpFile(preg_replace('#(\.sbp)?(\.php)?$#', '', $file)));
			}
			if(!static::fileExists($file, $phpFile))
			{
				throw new SbpException($file." not found", 1);
				return false;
			}
			return include_once($phpFile);
		}

		static protected function replace($content, $replace)
		{
			foreach($replace as $search => $replace)
			{
				$catched = false;
				try
				{
					$content = (is_callable($replace) ?
						preg_replace_callback($search, $replace, $content) :
						(substr($search, 0, 1) === '#' ?
							preg_replace($search, $replace, $content) :
							str_replace($search, $replace, $content)
						)
					);
				}
				catch(\Exception $e)
				{
					$catched = true;
					throw new SbpException('ERREUR PREG : \''.$e->getMessage()."' in:\n".$search, 1);
				}
				if(!$catched && preg_last_error())
				{
					throw new SbpException('ERREUR PREG '.preg_last_error()." in:\n".$search, 1);
				}
			}
			return $content;
		}

		static public function arrayShortSyntax($match)
		{
			return 'array(' .
				preg_replace('#,(\s*)$#', '$1', preg_replace('#^([\t ]*)('.self::VALIDNAME.')([\t ]*=)(.*[^,]),?(?=[\r\n]|$)#mU', '$1 \'$2\'$3>$4,', $match[1])) .
			')';
		}

		static public function replaceStrings($content)
		{
			foreach($GLOBALS['replaceStrings'] as $id => $string)
			{
				$content = str_replace(self::COMP.self::SUBST.$id.self::SUBST.self::COMP, $string, $content);
			}
			return $content;
		}

		static public function includeString($string)
		{
			return static::replaceString(var_export(static::replaceStrings(trim($string)), true));
		}

		static protected function replaceSuperMethods($content)
		{
			$method =  explode('::', __METHOD__);
			return preg_replace_callback(
				'#('.static::$validExpressionRegex.'|'.self::VALIDVAR.')-->#',
				function ($match) use($method)
				{
					return '(new \\Sbp\\Handler(' . call_user_func($method, $match[1]) . '))->';
				},
				$content
			);
		}

		static public function parse($content)
		{
			$detect = (strpos($content, 'trois =') !== false);
			$GLOBALS['replaceStrings'] = array();
			$GLOBALS['htmlCodes'] = array();
			$GLOBALS['quotedStrings'] = array();
			$GLOBALS['commentStrings'] = array();

			foreach(static::$plugins as $replace)
			{
				$content = is_array($replace) ? static::replace($content, $replace) : $replace($content);
			}

			$content = static::replace(

				/*****************************************/
				/* Mark the compiled file with a comment */
				/*****************************************/
				'<?php '.self::COMMENT.(is_null(static::$lastParsedFile) ? '' : '/*:'.static::$lastParsedFile.':*/').' ?>'.
				$content, array(


				/***************************/
				/* Complete PHP shrot-tags */
				/***************************/
				'#<\?(?!php)#'
					=> '<?php',


				/***************************/
				/* Remove useless PHP tags */
				/***************************/
				'#\?><\?php#'
					=> '',


				/*******************************/
				/* Escape the escape-character */
				/*******************************/
				self::SUBST
					=> self::SUBST.self::SUBST,


				/*************************************************************/
				/* Save the comments, quoted string and HTML out of PHP tags */
				/*************************************************************/
				'#'.self::COMMENTS.'|'.self::stringRegex().'|\?>.+<\?php#sU'
					=> array(get_class(), 'replaceString'),


				/*************************************/
				/* should key-word fo PHPUnit assert */
				/*************************************/
				'#(?<=\s|^)should\s+not(?=\s)$#mU'
					=> 'should not',

				'#^(\s*)(\S.*\s)?should\snot\s(.*[^;]);*\s*$#mU'
					=> function ($match)
					{
						list($all, $spaces, $before, $after) = $match;
						return $spaces . '>assertFalse(' .
							$before .
							preg_replace('#
								(?<![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff$])
								(?:be|return)
								(?![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff])
							#x', 'is', $after) . ', ' .
							static::includeString($all) .
						');';
					},

				'#^(\s*)(\S.*\s)?should(?!\snot)\s(.*[^;]);*\s*$#mU'
					=> function ($match)
					{
						list($all, $spaces, $before, $after) = $match;
						return $spaces . '>assertTrue(' .
							$before .
							preg_replace('#
								(?<![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff$])
								(?:be|return)
								(?![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff])
							#x', 'is', $after) . ', ' .
							static::includeString($all) .
						');';
					},
			));

			$validSubst = self::validSubst('(?:'.implode('|', $GLOBALS['quotedStrings']).')');
			$validComments = self::validSubst('(?:'.implode('|', $GLOBALS['commentStrings']).')');

			$__file = is_null(static::$lastParsedFile) ? null : realpath(static::$lastParsedFile);
			if($__file === false)
			{
				$__file = static::$lastParsedFile;
			}
			$__dir = is_null($__file) ? null : dirname($__file);
			$__file = static::includeString($__file);
			$__dir = static::includeString($__dir);
			$__server = array(
				'QUERY_STRING',
				'AUTH_USER',
				'AUTH_PW',
				'PATH_INFO',
				'REQUEST_METHOD',
				'USER_AGENT' => 'HTTP_USER_AGENT',
				'REFERER' => 'HTTP_REFERER',
				'HOST' => 'HTTP_HOST',
				'URI' => 'REQUEST_URI',
				'IP' => 'REMOTE_ADDR',
			);
			foreach($__server as $key => $value)
			{
				if(is_int($key))
				{
					$key = $value;
				}
				$content = preg_replace(
					'#(?<![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff$]|::|->)__' . preg_quote($key) . '(?![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff])#',
					'$_SERVER['.static::includeString($value).']',
					$content
				);
			}

			$content = static::replace($content, array(

				/*********/
				/* Class */
				/*********/
				'#
				(
					(?:^|\S\s*)
					\n[\t ]*
				)
				(
					(?:
						(?:'.self::ABSTRACT_SHORTCUTS.')
						\s+
					)?
					\\\\?
					(?:'.self::VALIDNAME.'\\\\)*
					'.self::VALIDNAME.'
				)
				(?:
					(?::|\s+:\s+|\s+extends\s+)
					(
						\\\\?
						'.self::VALIDNAME.'
						(?:\\\\'.self::VALIDNAME.')*
					)
				)?
				(?:
					(?:<<<|\s+<<<\s+|\s+implements\s+)
					(
						\\\\?
						'.self::VALIDNAME.'
						(?:\\\\'.self::VALIDNAME.')*
						(?:
							\s*,\s*
							\\\\?
							'.self::VALIDNAME.'
							(?:\\\\'.self::VALIDNAME.')*
						)*
					)
				)?
				(
					\s*
					(?:{(?:.*})?)?
					\s*\n
				)
				#xi'
					=> array(get_class(), 'parseClass'),


				/************************/
				/* Constantes spéciales */
				/************************/
				'#(?<![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff$]|::|->)__FILE(?![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff])#'
					=> $__file,

				'#(?<![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff$]|::|->)__DIR(?![a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff])#'
					=> $__dir,


				/*************/
				/* Constants */
				/*************/
				'#'.self::START.'('.self::CONSTNAME.')\s*=#'
					=> '$1const $2 =',

				'#\#('.self::CONSTNAME.')\s*=([^;]+);#'
					=> 'define("$1",$2);',

				'#([\(;\s\.+/*=])~:('.self::CONSTNAME.')#'
					=> '$1self::$2',

				'#([\(;\s\.+/*=]):('.self::CONSTNAME.')#'
					=> '$1static::$2',


				/*************/
				/* Functions */
				/*************/
				'#'.self::START.'<(?![\?=])#'
					=> '$1return ',

				'#'.self::START.'@f\s+('.self::VALIDNAME.')#'
					=> '$1if-defined-function $2',

				'#'.self::START.'f\s+('.self::VALIDNAME.')#'
					=> '$1function $2',

				'#(?<![a-zA-Z0-9_])f°\s*\(#'
					=> 'function(',

				'#(?<![a-zA-Z0-9_])f°\s*(\$|use|\{|\n|$)#'
					=> 'function $1',


				/****************/
				/* > to $this-> */
				/****************/
				'#([\(;\s\.+/*:+\/\*\?\&\|\!\^\~\[\{]\s*|return(?:\(\s*|\s+)|[=-]\s+)>(\$?'.self::VALIDNAME.')#'
					=> '$1$this->$2',


				/**************/
				/* Attributes */
				/**************/
				'#'.self::START.'-\s*(('.$validComments.'\s*)*\$'.self::VALIDNAME.')#U'
					=> '$1private $2',

				'#'.self::START.'\+\s*(('.$validComments.'\s*)*\$'.self::VALIDNAME.')#U'
					=> '$1public $2',

				'#'.self::START.'\*\s*(('.$validComments.'\s*)*\$'.self::VALIDNAME.')#U'
					=> '$1protected $2',

				'#'.self::START.'s-\s*(('.$validComments.'\s*)*\$'.self::VALIDNAME.')#U'
					=> '$1static private $2',

				'#'.self::START.'s\+\s*(('.$validComments.'\s*)*\$'.self::VALIDNAME.')#U'
					=> '$1static public $2',

				'#'.self::START.'s\*\s*(('.$validComments.'\s*)*\$'.self::VALIDNAME.')#U'
					=> '$1static protected $2',


				/***********/
				/* Methods */
				/***********/
				'#'.self::START.'\*\s*(('.$validComments.'\s*)*'.self::VALIDNAME.')#U'
					=> '$1protected function $2',

				'#'.self::START.'-\s*(('.$validComments.'\s*)*'.self::VALIDNAME.')#U'
					=> '$1private function $2',

				'#'.self::START.'\+\s*(('.$validComments.'\s*)*'.self::VALIDNAME.')#U'
					=> '$1public function $2',

				'#'.self::START.'s\*\s*(('.$validComments.'\s*)*'.self::VALIDNAME.')#U'
					=> '$1static protected function $2',

				'#'.self::START.'s-\s*(('.$validComments.'\s*)*'.self::VALIDNAME.')#U'
					=> '$1static private function $2',

				'#'.self::START.'s\+\s*(('.$validComments.'\s*)*'.self::VALIDNAME.')#U'
					=> '$1static public function $2',


				/**********/
				/* Switch */
				/**********/
				'#(\n\s*(?:'.$validComments.'\s*)*)(\S.*)\s+\:=#U'
					=> "$1switch($2)",

				'#(\n\s*(?:'.$validComments.'\s*)*)(\S.*)\s+\:\:#U'
					=> "$1case $2:",

				'#(\n\s*(?:'.$validComments.'\s*)*)d\:#'
					=> "$1default:",

				':;'
					=> "break;",


				/***********/
				/* Summons */
				/***********/
				'#(\$.*\S)\s*\*\*=\s*('.self::VALIDNAME.')\s*\(\s*\)#U'
					=> "$1 = $2($1)",

				'#(\$.*\S)\s*\*\*=\s*('.self::VALIDNAME.')\s*\(#U'
					=> "$1 = $2($1, ",

				'#([\(;\s\.+/*=\r\n]\s*)('.self::VALIDNAME.')\s*\(\s*\*\*\s*(\$[^\),]+)#'
					=> "$1$3 = $2($3",

				'#(\$.*\S)\s*\(\s*('.self::OPERATORS.')=\s*(\S)#U'
					=> "$1 = ($1 $2 $3",

				'#(\$.*\S)\s*('.self::OPERATORS.')=\s*(\S)#U'
					=> "$1 = $1 $2 $3",

				'#('.self::VALIDVAR.')\s*\!\?==\s*(\S[^;\n\r]*);#U'
					=> "if(!isset($1)) { $1 = $4; }",

				'#('.self::VALIDVAR.')\s*\!\?==\s*(\S[^;\n\r]*)(?=[;\n\r]|\$)#U'
					=> "if(!isset($1)) { $1 = $4; }",

				'#('.self::VALIDVAR.')\s*\!\?=\s*(\S[^;\n\r]*);#U'
					=> "if(!$1) { $1 = $4; }",

				'#('.self::VALIDVAR.')\s*\!\?=\s*(\S[^;\n\r]*)(?=[;\n\r]|\$)#U'
					=> "if(!$1) { $1 = $4; }",

				'#('.self::VALIDVAR.')\s*<->\s*('.self::VALIDVAR.')#U'
					=> "\$_sv = $4; $1 = \$_sv; unset(\$_sv)",

				'#('.self::VALIDVAR.')((?:\!\!|\!|~)\s*)(?=[\r\n;])#U'
					=> "$1 = $4$1",


				/***************/
				/* Comparisons */
				/***************/
				'#\seq\s#'
					=> " == ",

				'#\sne\s#'
					=> " != ",

				'#\sis\s#'
					=> " === ",

				'#\snot\s#'
					=> " !== ",

				'#\slt\s#'
					=> " < ",

				'#\sgt\s#'
					=> " > ",


				/**********************/
				/* Array short syntax */
				/**********************/
				'#{(\s*(?:\n+[\t ]*'.self::VALIDNAME.'[\t ]*=[^\n]+)*\s*)}#'
					=> array(get_class(), 'arrayShortSyntax'),


				/***********/
				/* Chainer */
				/***********/
				'#'.preg_quote(self::CHAINER).'('.self::PARENTHESES.')#'
					=> "(new \\Sbp\\Chainer($1))",

			));
			$content = explode("\n", $content);
			$curind = array();
			$previousRead = '';
			$previousWrite = '';
			$iRead = 0;
			$iWrite = 0;
			foreach($content as $index => &$line)
			{
				if(trim($line) !== '')
				{
					$espaces = strlen(str_replace("\t", '    ', $line))-strlen(ltrim($line));
					$c = empty($curind) ? -1 : end($curind);
					if($espaces > $c)
					{
						if(self::isBlock($previousRead, $content, $iRead))
						{
							if(substr(rtrim($previousRead), -1) !== '{'
							&& substr(ltrim($line), 0, 1) !== '{')
							{
								$curind[] = $espaces;
								$previousRead .= '{';
							}
						}
					}
					else if($espaces < $c)
					{
						if($c = substr_count($line, '}'))
						{
							$curind = array_slice($curind, 0, -$c);
						}
						while($espaces < ($pop = end($curind)))
						{
							if(trim($previousWrite, "\t }") === '')
							{
								if(strpos($previousWrite, '}') === false)
								{
									$previousWrite = str_repeat(' ', $espaces);
								}
								$previousWrite .= '}';
							}
							else
							{
								$s = strlen(ltrim($line));
								if($s && ($d = strlen($line) - $s) > 0)
								{
									$line = substr($line, 0, $d).'} '.substr($line, $d);
								}
								else
								{
									$line = '}'.$line;
								}
							}
							array_pop($curind);
						}
					}
					else
					{
						if(preg_match('#(?<![a-zA-Z0-9_\x7f-\xff$])('.self::MUST_CLOSE_BLOKCS.')(?![a-zA-Z0-9_\x7f-\xff])#', $previousRead))
						{
							$previousRead .= '{}';
						}
					}
					$previousRead = &$line;
					$iRead = $index;
				}
				$previousWrite = &$line;
				$iWrite = $index;
			}
			$content = implode("\n", $content);
			if(!empty($curind))
			{
				if(substr($content, -1) === "\n")
				{
					$content .= str_repeat('}', count($curind)) . "\n";
				}
				else
				{
					$content .= "\n" . str_repeat('}', count($curind));
				}
			}
			$valuesContent = $content;
			$values = array();
			$valueRegex = preg_quote(self::SUBST.self::VALUE).'([0-9]+)'.preg_quote(self::VALUE.self::SUBST);
			$valueRegexNonCapturant = preg_quote(self::SUBST.self::VALUE).'[0-9]+'.preg_quote(self::VALUE.self::SUBST);
			$validExpressionRegex = '(?<![a-zA-Z0-9_\x7f-\xff\$\\\\])(?:\$*[a-zA-Z0-9_\x7f-\xff\\\\]+(?:'.$valueRegexNonCapturant.')+|'.$valueRegexNonCapturant.'|'.$validSubst.'|[\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*|'.self::NUMBER.')';
			static::$validExpressionRegex = $validExpressionRegex;
			$restoreValues = function ($content) use(&$values)
			{
				foreach($values as $id => &$string)
				{
					if($string !== false)
					{
						$old = $content;
						$content = str_replace(self::SUBST.self::VALUE.$id.self::VALUE.self::SUBST, $string, $content);
						if($old !== $content)
						{
							$string = false;
						}
					}
				}
				return $content;
			};
			$filters = function ($content) use($restoreValues, &$values, $valueRegex, $valueRegexNonCapturant, $validSubst, $validExpressionRegex)
			{
				$keyWords = self::PHP_WORDS.'|'.self::OPERATORS.'|'.self::BLOKCS;
				return static::replaceSuperMethods(static::replace($content, array(
					/*********/
					/* Regex */
					/*********/
					'#(?<!\/)\/[^\/\n][^\n]*\/[Usimxe]*(?!\/)#'
						=> function ($match) use($restoreValues)
						{
							return static::includeString($restoreValues($match[0]));
						},

					/********************/
					/* Custom operators */
					/********************/
					'#('.$validExpressionRegex.')(?<!'.$keyWords.')[\t ]+(?!'.$keyWords.')([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)[\t ]+(?!'.$keyWords.')('.$validExpressionRegex.')#'
						=> function ($match) use($restoreValues, &$values)
						{
							list($all, $left, $keyWord, $right) = $match;
							$id = count($values);
							$values[$id] = $restoreValues('('.$left.', '.$right.')');
							return '__sbp_'.$keyWord.self::SUBST.self::VALUE.$id.self::VALUE.self::SUBST;
						},
				)));
			};
			$substituteValues = function ($match) use($restoreValues, &$values, $filters)
			{
				$id = count($values);
				$values[$id] = $restoreValues($filters($match[0]));
				return self::SUBST.self::VALUE.$id.self::VALUE.self::SUBST;
			};
			while(($content = preg_replace_callback('#[\(\[][^\(\)\[\]]*[\)\]]#', $substituteValues, $previous = $content, -1, $count)) && $count > 0);
			$content = $restoreValues($filters($content));
			$beforeSemiColon = '(' . $validSubst . '|\+\+|--|[a-zA-Z0-9_\x7f-\xff]!|[a-zA-Z0-9_\x7f-\xff]~|!!|[a-zA-Z0-9_\x7f-\xff\)\]])(?<!<\?php|<\?)';
			$content = static::replace($content, array(

				'#if-defined-(function\s+('.self::VALIDNAME.')([^\{]*)'.self::BRACES.')#'
					=> 'if(! function_exists(\'$2\')) { $1 }',

				/******************************/
				/* Complete with a semi-colon */
				/******************************/
				'#' . $beforeSemiColon . '(\s*(?:' . $validComments . '\s*)*[\n\r]+\s*(?:' . $validComments . '\s*)*)(?=[a-zA-Z0-9_\x7f-\xff\$\}]|$)#U'
					=> '$1;$2',

				'#' . $beforeSemiColon . '(\s*(?:' . $validComments . '\s*)*)$#U'
					=> '$1;$2',

				'#' . $beforeSemiColon . '(\s*(?:' . $validComments . '\s*)*\?>)$#U'
					=> '$1;$2',

			));
			$content = static::replaceStrings($content);
			$content = static::replace($content, array(

				"\r" => ' ',

				self::SUBST.self::SUBST
					=> self::SUBST,

				'#(?<![a-zA-Z0-9_\x7f-\xff\$])('.self::IF_BLOKCS.')(?:\s+(\S.*))?\s*\{#U'
					=> '$1 ($2) {',

				'#(?<![a-zA-Z0-9_\x7f-\xff\$])(function\s+'.self::VALIDNAME.')(?:\s+(array\s.+|[A-Z\$\&].+))?\s*\{#U'
					=> '$1 ($2) {',

				'#(?<![a-zA-Z0-9_\x7f-\xff\$])function\s*(array\s.+|[A-Z\$\&].+)?\s*\{#U'
					=> 'function ($1) {',

				'#(?<![a-zA-Z0-9_\x7f-\xff\$])function\s+use(?![a-zA-Z0-9_\x7f-\xff])#U'
					=> 'function () use',

				'#(?<![a-zA-Z0-9_\x7f-\xff\$])(function.*[^a-zA-Z0-9_\x7f-\xff\$])use\s*((array\s.+|[A-Z\$\&].+)\{)#U'
					=> '$1 ) use ( $2',

				'#\((\([^\(\)]+\))\)#'
					=> '$1',

				'#(catch\s*\([^\)]+\)\s*)([^\s\{])#'
					=> '$1{} $2',

				'#\(('.self::PARENTHESES.')\)#'
					=> '$1'

			));
			return $content;
		}
	}
}

namespace
{

	function sbp_include($file, $once = false)
	{
		$method = $once ? 'includeOnceFile' : 'includeFile';
		return Sbp\Sbp::$method($file);
	}


	function sbp_include_once($file)
	{
		return Sbp\Sbp::includeOnceFile($file);
	}


	function sbp($file, $once = false)
	{
		return sbp_include($file, $once);
	}


	function sbp_include_if_exists($file, $once = false)
	{
		try
		{
			return sbp_include($file, $once);
		}
		catch(Sbp\SbpException $e)
		{
			return false;
		}
	}

	function sbp_benchmark($title = '')
	{
		Sbp\Sbp::benchmark($title);
	}

	function sbp_benchmark_end()
	{
		Sbp\Sbp::benchmarkEnd();
	}

	function sbp_add_plugin($plugin, $from, $to = null)
	{
		Sbp\Sbp::addPlugin($plugin, $from, $to);
	}

	function sbp_remove_plugin($plugin)
	{
		Sbp\Sbp::removePlugin($plugin);
	}

}

?>