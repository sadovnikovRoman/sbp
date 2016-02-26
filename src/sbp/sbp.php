<?php

namespace Sbp;

include_once __DIR__.'/functions.php';

class Sbp
{
    const COMMENT = '/* Generated By SBP */';
    const VALIDNAME = '[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*';
    const NUMBER = '(?:0[xbXB])?[0-9]*\.?[0-9]+(?:[eE](?:0[xbXB])?[0-9]*\.?[0-9]+)?';
    const VALIDVAR = '(?<!\$)\$+[^\$\n\r=]+([\[\{]((?>[^\[\{\]\}]+)|(?-2))*[\]\}])?(?![a-zA-Z0-9_\x7f-\xff\$\[\{])';
    const BRACES = '(\{((?>[^\{\}]+)|(?-2))*\})';
    const BRAKETS = '(\[((?>[^\[\]]+)|(?-2))*\])';
    const PARENTHESES = '(\(((?>[^\(\)]+)|(?-2))*\))';
    const CONSTNAME = '[A-Z_]+';
    const SUBST = '÷';
    const COMP = '`';
    const VALUE = 'µ';
    const CHAINER = '¤';
    const COMMENTS = '(?:\/\/|\#).*(?=\n)|\/\*(?:.|\n)*\*\/';
    const OPERATORS = '\|\||\&\&|or|and|xor|is|not|<>|lt|gt|<=|>=|\!==|===|\?\:';
    const PHP_WORDS = 'true|false|null|echo|print|static|yield|var|exit|as|case|default|clone|endswtch|endwhile|endfor|endforeach|callable|endif|enddeclare|final|finally|label|goto|const|global|namespace|instanceof|new|throw|include|require|include_once|require_once|use|exit|continue|return|break|extends|implements|abstract|public|protected|private|function|interface';
    const BLOKCS = 'if|else|elseif|try|catch|function|class|trait|switch|while|for|foreach|do';
    const ALLOW_ALONE_CUSTOM_OPERATOR = 'if|elseif|foreach|for|while|or|and|xor';
    const MUST_CLOSE_BLOKCS = 'try|catch|function|class|trait|switch|interface';
    const IF_BLOKCS = 'if|elseif|catch|switch|while|for|foreach';
    const START = '((?:^|[\n;\{\}])(?:(?:\/\/|\#).*(?=\n)|\/\*(?:.|\n)*\*\/\s*)*\s*)';
    const ABSTRACT_SHORTCUTS = 'abstract|abst|abs|a';
    const BENCHMARK_END = -1;

    const SAME_DIR = 0x01;

    protected static $prod = false;
    protected static $destination = 0x01;
    protected static $callbackWriteIn = null;
    protected static $lastParsedFile = null;
    protected static $plugins = array();
    protected static $validExpressionRegex = null;

    public static function prod($on = true)
    {
        static::$prod = (bool) $on;
    }

    public static function dev($off = true)
    {
        static::$prod = !$off;
    }

    public static function addPlugin($plugin, $from, $to = null)
    {
        if (!is_null($to)) {
            if (is_array($from) || is_object($from)) {
                throw new SbpException('Invalid arguments, if the second argument is an array or an object, do not specified a third argument.');
            }
            $from = array($from => $to);
        }
        static::$plugins[$plugin] = $from;
    }

    public static function removePlugin($plugin)
    {
        unset(static::$plugins[$plugin]);
    }

    public static function benchmarkEnd()
    {
        static::benchmark(static::BENCHMARK_END);
    }

    protected static function getBenchmarkHtml(&$list)
    {
        $previous = null;
        $times = array_keys($list);
        $len = max(0, min(2, max(array_map(function ($key) {
            $key = explode('.', $key);

            return strlen(end($key)) - 3;
        }, $times))));
        $list[strval(microtime(true))] = 'End benchmark';
        $ul = '';
        foreach ($list as $time => $title) {
            $ul .= '<li>'.(is_null($previous) ? '' : '<b>'.number_format(($time - $previous) * 1000, $len).'ms</b>').$title.'</li>';
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
                    <ul>'.$ul.'</ul>
                    <p>All: <b>'.number_format((end($times) - reset($times)) * 1000, $len, ',', ' ').'ms</b></p>
                    <h1>Code source</h1>
                    <pre>'.htmlspecialchars(ob_get_clean()).'</pre>
                </body>
            </html>';
    }

    protected static function recordBenchmark(&$list, $title)
    {
        $time = strval(microtime(true));
        if (empty($title)) {
            $list = array($time => 'Start benchmark');
            ob_start();
        } elseif (is_array($list)) {
            if ($title === static::BENCHMARK_END) {
                exit(static::getBenchmarkHtml($list));
            } else {
                $list[$time] = $title;
            }
        }
    }

    public static function benchmark($title = '')
    {
        static $list = null;

        return static::recordBenchmark($list, $title);
    }

    public static function writeIn($directory = null, $callback = null)
    {
        if (is_null($directory)) {
            $directory = static::SAME_DIR;
        }
        if ($directory !== static::SAME_DIR) {
            $directory = rtrim($directory, '/\\');
            if (!file_exists($directory)) {
                throw new SbpException($directory.' : path not found');
            }
            if (!is_writable($directory)) {
                throw new SbpException($directory.' : persmission denied');
            }
            $directory .= DIRECTORY_SEPARATOR;
        }
        static::$destination = $directory;
        if (!is_null($callback)) {
            if (!is_callable($callback)) {
                throw new SbpException('Invalid callback');
            }
            static::$callbackWriteIn = $callback;
        }
    }

    public static function isSbp($file)
    {
        return
            strpos($file, $k = ' '.static::COMMENT) !== false ||
            (
                substr($file, 0, 1) === '/' &&
                @file_exists($file) &&
                strpos(file_get_contents($file), $k) !== false
            );
    }

    public static function parseClass($match)
    {
        list($all, $start, $class, $extend, $implement, $end) = $match;
        $class = trim($class);
        if (in_array(substr($all, 0, 1), str_split(',(+-/*&|'))
        || in_array($class, array_merge(
            array('else', 'try', 'default:', 'echo', 'print', 'exit', 'continue', 'break', 'return', 'do'),
            explode('|', static::PHP_WORDS)
        ))) {
            return $all;
        }
        $className = preg_replace('#^(?:'.static::ABSTRACT_SHORTCUTS.')\s+#', '', $class, -1, $isAbstract);
        $codeLine = $start.($isAbstract ? 'abstract ' : '').'class '.$className.
            (empty($extend) ? '' : ' extends '.trim($extend)).
            (empty($implement) ? '' : ' implements '.trim($implement)).
            ' '.trim($end);

        return $codeLine.str_repeat("\n", substr_count($all, "\n") - substr_count($codeLine, "\n"));
    }

    private static function findLastBlock(&$line, $block = array())
    {
        $pos = false;
        if (empty($block)) {
            $block = explode('|', static::BLOKCS);
        }
        if (!is_array($block)) {
            $block = array($block);
        }
        foreach ($block as $word) {
            if (preg_match('#(?<![a-zA-Z0-9$_])'.$word.'(?![a-zA-Z0-9_])#s', $line, $match, PREG_OFFSET_CAPTURE)) {
                $p = $match[0][1] + 1;
                if ($pos === false || $p > $pos) {
                    $pos = $p;
                }
            }
        }

        return $pos;
    }

    public static function isBlock(&$line, &$grouped, $iRead = 0)
    {
        if (substr(rtrim($line), -1) === ';') {
            return false;
        }
        $find = static::findLastBlock($line);
        $pos = $find ?: 0;
        $ouvre = substr_count($line, '(', $pos);
        $ferme = substr_count($line, ')', $pos);
        if ($ouvre > $ferme) {
            return false;
        }
        if ($ouvre < $ferme) {
            $c = $ferme - $ouvre;
            $content = ' '.implode("\n", array_slice($grouped, 0, $iRead));
            while ($c !== 0) {
                $ouvre = strrpos($content, '(') ?: 0;
                $ferme = strrpos($content, ')') ?: 0;
                if ($ouvre === 0 && $ferme === 0) {
                    return false;
                }
                if ($ouvre > $ferme) {
                    $c--;
                    $content = substr($content, 0, $ouvre);
                } else {
                    $c++;
                    $content = substr($content, 0, $ferme);
                }
            }
            $content = substr($content, 1);
            $find = static::findLastBlock($content);
            $pos = $find ?: 0;

            return $find !== false && !preg_match('#(?<!->)\s*\{#U', substr($content, $pos));
        }

        return $find !== false && !preg_match('#(?<!->)\s*\{#U', substr($line, $pos));
    }

    public static function contentTab($match)
    {
        return $match[1].str_replace("\n", "\n".$match[1], $GLOBALS['sbpContentTab']);
    }

    public static function container($container, $file, $content, $basename = null, $name = null)
    {
        $basename = $basename ?: basename($file);
        $name = $name ?: preg_replace('#\..+$#', '', $basename);
        $camelCase = preg_replace_callback('#[-_]([a-z])#', function ($match) { return strtoupper($match[1]); }, $name);
        $replace = array(
            '{file}' => $file,
            '{basename}' => $basename,
            '{name}' => $name,
            '{camelCase}' => $camelCase,
            '{CamelCase}' => ucfirst($camelCase),
        );
        $GLOBALS['sbpContentTab'] = $content;
        $container = preg_replace_callback('#(\t*){content}#', array(get_class(), 'contentTab'), $container);
        unset($GLOBALS['sbpContentTab']);

        return str_replace(array_keys($replace), array_values($replace), $container);
    }

    public static function parseWithContainer($container, $file, $content, $basename = null, $name = null)
    {
        $content=static::container($container,$file,'/*sbp-container-end*/'.$content,$fin);
        $content=static::parse($content);
        $content=explode('/*sbp-container-end*/', $content, 2);
        $content[0]=strtr($content[0],"\r\n","  ");

        return implode('',$content);
    }

    public static function replaceString($match)
    {
        if (is_array($match)) {
            $match = $match[0];
        }
        $id = count($GLOBALS['replaceStrings']);
        $GLOBALS['replaceStrings'][$id] = $match;
        if (in_array(substr($match, 0, 1), array('/', '#'))) {
            $GLOBALS['commentStrings'][] = $id;
        } elseif (strpos($match, '?') === 0) {
            $GLOBALS['htmlCodes'][] = $id;
        } else {
            $GLOBALS['quotedStrings'][] = $id;
        }

        return static::COMP.static::SUBST.$id.static::SUBST.static::COMP;
    }

    protected static function stringRegex()
    {
        $antislash = preg_quote('\\');

        return '([\'"]).*(?<!'.$antislash.')(?:'.$antislash.$antislash.')*\\1';
    }

    protected static function validSubst($motif = '[0-9]+')
    {
        if ($motif === '(?:)') {
            $motif = '(?:[^\S\s])';
        }

        return preg_quote(static::COMP.static::SUBST).$motif.preg_quote(static::SUBST.static::COMP);
    }

    public static function fileMatchnigLetter($file)
    {
        if (fileowner($file) === getmyuid()) {
            return 'u';
        }
        if (filegroup($file) === getmygid()) {
            return 'g';
        }

        return 'o';
    }

    public static function fileParse($from, $to = null)
    {
        if (is_null($to)) {
            $to = $from;
        }
        if (!is_readable($from)) {
            throw new SbpException($from.' is not readable, try :\nchmod '.static::fileMatchnigLetter($from).'+r '.$from, 1);

            return false;
        }
        if (!is_writable($dir = dirname($to))) {
            throw new SbpException($dir.' is not writable, try :\nchmod '.static::fileMatchnigLetter($dir).'+w '.$dir, 1);

            return false;
        }
        static::$lastParsedFile = $from;
        $writed = file_put_contents($to, static::parse(file_get_contents($from)));
        static::$lastParsedFile = null;

        return $writed;
    }

    public static function phpFile($file)
    {
        $callback = (is_null(static::$callbackWriteIn) ?
            'sha1' :
            static::$callbackWriteIn
        );

        return static::$destination === static::SAME_DIR
            ? $file.'.php'
            : static::$destination.$callback($file).'.php';
    }

    public static function fileExists($file, &$phpFile = null)
    {
        $file = preg_replace('#(\.sbp)?(\.php)?$#', '', $file);
        $sbpFile = $file.'.sbp.php';
        $callback = (is_null(static::$callbackWriteIn) ?
            'sha1' :
            static::$callbackWriteIn
        );
        $phpFile = static::phpFile($file);
        if (!file_exists($phpFile)) {
            if (file_exists($sbpFile)) {
                static::fileParse($sbpFile, $phpFile);

                return true;
            }
        } else {
            if (file_exists($sbpFile) && filemtime($sbpFile) > filemtime($phpFile)) {
                static::fileParse($sbpFile, $phpFile);
            }

            return true;
        }

        return false;
    }

    public static function sbpFromFile($file)
    {
        if (preg_match('#/*:(.+):*/#U', file_get_contents($file), $match)) {
            return $match[1];
        }
    }

    public static function includeFile($file)
    {
        if (static::$prod) {
            return include static::phpFile(preg_replace('#(\.sbp)?(\.php)?$#', '', $file));
        }
        if (!static::fileExists($file, $phpFile)) {
            throw new SbpException($file.' not found', 1);

            return false;
        }

        return include $phpFile;
    }

    public static function includeOnceFile($file)
    {
        if (static::$prod) {
            return include_once(static::phpFile(preg_replace('#(\.sbp)?(\.php)?$#', '', $file)));
        }
        if (!static::fileExists($file, $phpFile)) {
            throw new SbpException($file.' not found', 1);

            return false;
        }

        return include_once $phpFile;
    }

    protected static function replace($content, $replace)
    {
        foreach ($replace as $search => $replace) {
            $catched = false;
            try {
                $content = (is_callable($replace) ?
                    preg_replace_callback($search, $replace, $content) :
                    (substr($search, 0, 1) === '#' ?
                        preg_replace($search, $replace, $content) :
                        str_replace($search, $replace, $content)
                    )
                );
            } catch (\Exception $e) {
                $catched = true;
                throw new SbpException('ERREUR PREG : \''.$e->getMessage()."' in:\n".$search, 1);
            }
            if (!$catched && preg_last_error()) {
                throw new SbpException('ERREUR PREG '.preg_last_error()." in:\n".$search, 1);
            }
        }

        return $content;
    }

    public static function arrayShortSyntax($match)
    {
        return 'array('.
            preg_replace('#,(\s*)$#', '$1', preg_replace('#^([\t ]*)('.static::VALIDNAME.')([\t ]*=)(.*[^,]),?(?=[\r\n]|$)#mU', '$1 \'$2\'$3>$4,', $match[1])).
        ')';
    }

    public static function replaceStrings($content)
    {
        foreach ($GLOBALS['replaceStrings'] as $id => $string) {
            $content = str_replace(static::COMP.static::SUBST.$id.static::SUBST.static::COMP, $string, $content);
        }

        return $content;
    }

    public static function includeString($string)
    {
        return static::replaceString(var_export(static::replaceStrings(trim($string)), true));
    }

    protected static function replaceSuperMethods($content)
    {
        $method = explode('::', __METHOD__);

        return preg_replace_callback(
            '#('.static::$validExpressionRegex.'|'.static::VALIDVAR.')-->#',
            function ($match) use($method) {
                return '(new \\Sbp\\Handler('.call_user_func($method, $match[1]).'))->';
            },
            $content
        );
    }

    private static function loadPlugins($content)
    {
        foreach (static::$plugins as $replace) {
            $content = is_array($replace)
                ? static::replace($content, $replace)
                : (is_callable($replace) || is_string($replace)
                    ? $replace($content)
                    : static::replace($content, (array) $replace)
                );
        }

        return $content;
    }

    public static function parse($content)
    {
        $GLOBALS['replaceStrings'] = array();
        $GLOBALS['htmlCodes'] = array();
        $GLOBALS['quotedStrings'] = array();
        $GLOBALS['commentStrings'] = array();

        $content = static::loadPlugins($content);

        $content = static::replace(

            /*****************************************/
            /* Mark the compiled file with a comment */
            /*****************************************/
            '<?php '.static::COMMENT.(is_null(static::$lastParsedFile) ? '' : '/*:'.static::$lastParsedFile.':*/').' ?>'.
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
            static::SUBST
                => static::SUBST.static::SUBST,


            /*************************************************************/
            /* Save the comments, quoted string and HTML out of PHP tags */
            /*************************************************************/
            '#'.static::COMMENTS.'|'.static::stringRegex().'|\?>.+<\?php#sU'
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

        $validSubst = static::validSubst('(?:'.implode('|', $GLOBALS['quotedStrings']).')');
        $validComments = static::validSubst('(?:'.implode('|', $GLOBALS['commentStrings']).')');

        $__file = is_null(static::$lastParsedFile) ? null : realpath(static::$lastParsedFile);
        if ($__file === false) {
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
        foreach ($__server as $key => $value) {
            if (is_int($key)) {
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
                    (?:'.static::ABSTRACT_SHORTCUTS.')
                    \s+
                )?
                \\\\?
                (?:'.static::VALIDNAME.'\\\\)*
                '.static::VALIDNAME.'
            )
            (?:
                (?::|\s+:\s+|\s+extends\s+)
                (
                    \\\\?
                    '.static::VALIDNAME.'
                    (?:\\\\'.static::VALIDNAME.')*
                )
            )?
            (?:
                (?:<<<|\s+<<<\s+|\s+implements\s+)
                (
                    \\\\?
                    '.static::VALIDNAME.'
                    (?:\\\\'.static::VALIDNAME.')*
                    (?:
                        \s*,\s*
                        \\\\?
                        '.static::VALIDNAME.'
                        (?:\\\\'.static::VALIDNAME.')*
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
            '#'.static::START.'('.static::CONSTNAME.')\s*=#'
                => '$1const $2 =',

            '#\#('.static::CONSTNAME.')\s*=([^;]+);#'
                => 'define("$1",$2);',

            '#([\(;\s\.+/*=])~:('.static::CONSTNAME.')#'
                => '$1static::$2',

            '#([\(;\s\.+/*=]):('.static::CONSTNAME.')#'
                => '$1static::$2',


            /*************/
            /* Functions */
            /*************/
            '#'.static::START.'<(?![\?=])#'
                => '$1return ',

            '#'.static::START.'@f[\t ]+('.static::VALIDNAME.')#'
                => '$1if-defined-function $2',

            '#'.static::START.'f[\t ]+('.static::VALIDNAME.')#'
                => '$1function $2',

            '#(?<![a-zA-Z0-9_])f°[\t ]*\(#'
                => 'function(',

            '#(?<![a-zA-Z0-9_])f°([\t ]*(?:\n|\r|$))#'
                => 'function ()$1',

            '#(?<![a-zA-Z0-9_])f°([\t ]*(?:\$|use|\{|\n|$))#'
                => 'function$1',


            /****************/
            /* > to $this-> */
            /****************/
            '#([\(;\s\.+/*:+\/\*\?\&\|\!\^\~\[\{]\s*|return(?:\(\s*|\s+)|[=-]\s+)>(\$?'.static::VALIDNAME.')#'
                => '$1$this->$2',


            /**************/
            /* Attributes */
            /**************/
            '#'.static::START.'-\s*(('.$validComments.'\s*)*\$'.static::VALIDNAME.')#U'
                => '$1private $2',

            '#'.static::START.'\+\s*(('.$validComments.'\s*)*\$'.static::VALIDNAME.')#U'
                => '$1public $2',

            '#'.static::START.'\*\s*(('.$validComments.'\s*)*\$'.static::VALIDNAME.')#U'
                => '$1protected $2',

            '#'.static::START.'s-\s*(('.$validComments.'\s*)*\$'.static::VALIDNAME.')#U'
                => '$1static private $2',

            '#'.static::START.'s\+\s*(('.$validComments.'\s*)*\$'.static::VALIDNAME.')#U'
                => '$1public static $2',

            '#'.static::START.'s\*\s*(('.$validComments.'\s*)*\$'.static::VALIDNAME.')#U'
                => '$1protected static $2',


            /***********/
            /* Methods */
            /***********/
            '#'.static::START.'\*[\t ]*(('.$validComments.'[\t ]*)*'.static::VALIDNAME.')#U'
                => '$1protected function $2',

            '#'.static::START.'-[\t ]*(('.$validComments.'[\t ]*)*'.static::VALIDNAME.')#U'
                => '$1private function $2',

            '#'.static::START.'\+[\t ]*(('.$validComments.'[\t ]*)*'.static::VALIDNAME.')#U'
                => '$1public function $2',

            '#'.static::START.'s\*[\t ]*(('.$validComments.'[\t ]*)*'.static::VALIDNAME.')#U'
                => '$1protected static function $2',

            '#'.static::START.'s-[\t ]*(('.$validComments.'[\t ]*)*'.static::VALIDNAME.')#U'
                => '$1static private function $2',

            '#'.static::START.'s\+[\t ]*(('.$validComments.'[\t ]*)*'.static::VALIDNAME.')#U'
                => '$1public static function $2',


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
            '#(\$.*\S)\s*\*\*=\s*('.static::VALIDNAME.')\s*\(\s*\)#U'
                => "$1 = $2($1)",

            '#(\$.*\S)\s*\*\*=\s*('.static::VALIDNAME.')\s*\(#U'
                => "$1 = $2($1, ",

            '#([\(;\s\.+/*=\r\n]\s*)('.static::VALIDNAME.')\s*\(\s*\*\*\s*(\$[^\),]+)#'
                => "$1$3 = $2($3",

            '#(\$.*\S)\s*\(\s*('.static::OPERATORS.')=\s*(\S)#U'
                => "$1 = ($1 $2 $3",

            '#(\$.*\S)\s*('.static::OPERATORS.')=\s*(\S)#U'
                => "$1 = $1 $2 $3",

            '#('.static::VALIDVAR.')\s*\!\?==\s*(\S[^;\n\r]*);#U'
                => "if (!isset($1)) { $1 = $4; }",

            '#('.static::VALIDVAR.')\s*\!\?==\s*(\S[^;\n\r]*)(?=[;\n\r]|\$)#U'
                => "if (!isset($1)) { $1 = $4; }",

            '#('.static::VALIDVAR.')\s*\!\?=\s*(\S[^;\n\r]*);#U'
                => "if (!$1) { $1 = $4; }",

            '#('.static::VALIDVAR.')\s*\!\?=\s*(\S[^;\n\r]*)(?=[;\n\r]|\$)#U'
                => "if (!$1) { $1 = $4; }",

            '#('.static::VALIDVAR.')\s*<->\s*('.static::VALIDVAR.')#U'
                => "\$_sv = $4; $4 = $1; $1 = \$_sv; unset(\$_sv)",

            '#('.static::VALIDVAR.')((?:\!\!|\!|~)\s*)(?=[\r\n;])#U'
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
            '#{(\s*(?:\n+[\t ]*'.static::VALIDNAME.'[\t ]*=[^\n]+)+\s*)}#'
                => array(get_class(), 'arrayShortSyntax'),


            /***********/
            /* Chainer */
            /***********/
            '#'.preg_quote(static::CHAINER).'('.static::PARENTHESES.')#'
                => "(new \\Sbp\\Chainer($1))",

        ));
        $content = explode("\n", $content);
        $curind = array();
        $previousRead = '';
        $previousWrite = '';
        $iRead = 0;
        $iWrite = 0;
        foreach ($content as $index => &$line) {
            if (trim($line) !== '') {
                $espaces = strlen(str_replace("\t", '    ', $line))-strlen(ltrim($line));
                $c = empty($curind) ? -1 : end($curind);
                if ($espaces > $c) {
                    if (static::isBlock($previousRead, $content, $iRead)) {
                        if (substr(rtrim($previousRead), -1) !== '{'
                        && substr(ltrim($line), 0, 1) !== '{') {
                            $curind[] = $espaces;
                            $previousRead .= '{';
                        }
                    }
                } else if ($espaces < $c) {
                    if ($c = substr_count($line, '}')) {
                        $curind = array_slice($curind, 0, -$c);
                    }
                    while ($espaces < ($pop = end($curind))) {
                        if (trim($previousWrite, "\t }") === '') {
                            if (strpos($previousWrite, '}') === false) {
                                $previousWrite = str_repeat(' ', $espaces);
                            }
                            $previousWrite .= '}';
                        } else {
                            $s = strlen(ltrim($line));
                            if ($s && ($d = strlen($line) - $s) > 0) {
                                $line = substr($line, 0, $d).'} '.substr($line, $d);
                            } else {
                                $line = '}'.$line;
                            }
                        }
                        array_pop($curind);
                    }
                } else {
                    if (preg_match('#(?<![a-zA-Z0-9_\x7f-\xff$\(])('.static::MUST_CLOSE_BLOKCS.')(?![a-zA-Z0-9_\x7f-\xff])#', $previousRead)) {
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
        if (!empty($curind)) {
            if (substr($content, -1) === "\n") {
                $content .= str_repeat('}', count($curind)) . "\n";
            } else {
                $content .= "\n" . str_repeat('}', count($curind));
            }
        }
        $valuesContent = $content;
        $values = array();
        $valueRegex = preg_quote(static::SUBST.static::VALUE).'([0-9]+)'.preg_quote(static::VALUE.static::SUBST);
        $valueRegexNonCapturant = preg_quote(static::SUBST.static::VALUE).'[0-9]+'.preg_quote(static::VALUE.static::SUBST);
        $validExpressionRegex = '(?<![a-zA-Z0-9_\x7f-\xff\$\\\\])(?:[a-zA-Z0-9_\x7f-\xff\\\\]+(?:'.$valueRegexNonCapturant.')+|\$+[a-zA-Z0-9_\x7f-\xff\\\\]+(?:'.$valueRegexNonCapturant.')*|'.$valueRegexNonCapturant.'|'.$validSubst.'|[\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*|'.static::NUMBER.')';
        static::$validExpressionRegex = $validExpressionRegex;
        $restoreValues = function ($content) use(&$values)
        {
            foreach ($values as $id => &$string) {
                if ($string !== false) {
                    $old = $content;
                    $content = str_replace(static::SUBST.static::VALUE.$id.static::VALUE.static::SUBST, $string, $content);
                    if ($old !== $content) {
                        $string = false;
                    }
                }
            }

            return $content;
        };
        $aloneCustomOperator = implode('|', array_map(
            function ($value)
            {
                return '(?<![a-zA-Z0-9_])'.$value;
            },
            explode('|', static::ALLOW_ALONE_CUSTOM_OPERATOR)
        ));
        static $previousKeyWords = null;
        static $keyWords = null;
        if (is_null($previousKeyWords)) {
            $previousKeyWords = static::PHP_WORDS.'|'.static::OPERATORS.'|'.static::MUST_CLOSE_BLOKCS;
        }
        if (is_null($keyWords)) {
            $keyWords = static::PHP_WORDS.'|'.static::OPERATORS.'|'.static::BLOKCS;
        }
        $filters = function ($content) use($previousKeyWords, $keyWords, $aloneCustomOperator, $restoreValues, &$values, $valueRegex, $valueRegexNonCapturant, $validSubst, $validExpressionRegex)
        {
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
                '#(?<=^|[,\n=*\/\^%&|<>!+-]|'.$aloneCustomOperator.')[\n\t ]+(?!'.$keyWords.'|array|['.static::SUBST.static::VALUE.static::COMP.'\[\]\(\)\{\}])([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)[\t ]+(?!'.$keyWords.')('.$validExpressionRegex.')(?!::|[a-zA-Z0-9_\x7f-\xff])#'
                    => function ($match) use($restoreValues, &$values)
                    {
                        list($all, $keyWord, $right) = $match;
                        $id = count($values);
                        $values[$id] = $restoreValues('('.$right.')');

                        return ' __sbp_'.$keyWord.static::SUBST.static::VALUE.$id.static::VALUE.static::SUBST;
                    },

                '#('.$validExpressionRegex.')(?<!'.$previousKeyWords.')[\t ]+(?!'.$keyWords.'|array|['.static::SUBST.static::VALUE.static::COMP.'\[\]\(\)\{\}])([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)[\t ]+(?!'.$keyWords.')('.$validExpressionRegex.')(?!::|[a-zA-Z0-9_\x7f-\xff])#'
                    => function ($match) use($restoreValues, &$values)
                    {
                        list($all, $left, $keyWord, $right) = $match;
                        $id = count($values);
                        $values[$id] = $restoreValues('('.$left.', '.$right.')');

                        return ' __sbp_'.$keyWord.static::SUBST.static::VALUE.$id.static::VALUE.static::SUBST;
                    },
            )));
        };
        $substituteValues = function ($match) use($restoreValues, &$values, $filters)
        {
            $id = count($values);
            $values[$id] = $restoreValues($filters($match[0]));

            return static::SUBST.static::VALUE.$id.static::VALUE.static::SUBST;
        };
        while (($content = preg_replace_callback('#[\(\[][^\(\)\[\]]*[\)\]]#', $substituteValues, $content, -1, $count)) && $count > 0);
        $content = $restoreValues($filters($content));
        $beforeSemiColon = '(' . $validSubst . '|\+\+|--|[a-zA-Z0-9_\x7f-\xff]!|[a-zA-Z0-9_\x7f-\xff]~|!!|[a-zA-Z0-9_\x7f-\xff\)\]])(?<!<\?php|<\?)';
        $content = static::replace($content, array(

            '#if-defined-(function\s+('.static::VALIDNAME.')([^\{]*)'.static::BRACES.')#'
                => 'if (! function_exists(\'$2\')) { $1 }',

            /******************************/
            /* Complete with a semi-colon */
            /******************************/
            '#' . $beforeSemiColon . '(\s*(?:' . $validComments . '\s*)*[\n\r]+\s*(?:' . $validComments . '\s*)*)(?=[a-zA-Z0-9_\x7f-\xff\$\}]|$)#U'
                => '$1;$2',

            '#' . $beforeSemiColon . '(\s*(?:' . $validComments . '\s*)*)$#U'
                => '$1;$2',

            '#' . $beforeSemiColon . '(\s*(?:' . $validComments . '\s*)*\?>)$#U'
                => '$1;$2',

            '#(' . $validSubst . '|\+\+|--|[a-zA-Z0-9_\x7f-\xff]!|[a-zA-Z0-9_\x7f-\xff]~|!!|\]|\))(\s*\n\s*\()#U'
                => '$1;$2',

            '#(?<=^|\s)(function\s[^{]+);#U'
                => '$1 {}',

            '#(?<![a-zA-Z0-9_\x7f-\xff\$])function(\s[^{]*);#'
                => 'function$1 {}',

            '#(?<![a-zA-Z0-9_\x7f-\xff\$])('.static::IF_BLOKCS.')(?:[\t ]+(\S.*))?(?<!->)\s*\{#U'
                => '$1 ($2) {',

            '#(?<![a-zA-Z0-9_\x7f-\xff\$])(function[\t ]+'.static::VALIDNAME.')(?:[\t ]+(array[\t ].+|_*[A-Z\$\&\\\\].+))?(?<!->)\s*\{#U'
                => '$1 ($2) {',

            '#(?<![a-zA-Z0-9_\x7f-\xff\$])function[\t ]+(array[\t ].+|_*[A-Z\$\&\\\\].+)?(?<!->)\s*\{#U'
                => 'function ($1) {',

            '#(?<![a-zA-Z0-9_\x7f-\xff\$])function\s+use(?![a-zA-Z0-9_\x7f-\xff])#U'
                => 'function () use',

            '#(?<![a-zA-Z0-9_\x7f-\xff\$])(function.*[^a-zA-Z0-9_\x7f-\xff\$])use[\t ]*((array[\t ].+|_*[A-Z\$\&\\\\].+)(?<!->)[\t ]*\{)#U'
                => '$1) use ($2',

            '#\((\([^\(\)]+\))\)#'
                => '$1',

            '#(catch\s*\([^\)]+\)\s*)([^\s\{])#'
                => '$1{} $2',

        ));
        $content = static::replaceStrings($content);
        $content = static::replace($content, array(

            "\r" => ' ',

            static::SUBST.static::SUBST
                => static::SUBST,

            '#\(('.static::PARENTHESES.')\)#'
                => '$1',

        ));

        return $content;
    }
}
