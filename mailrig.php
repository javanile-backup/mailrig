#!/usr/bin/env php
<?php
/**
 * Mailman
 *
 * the only tool managing email boxes.
 */

error_reporting(E_ALL);
date_default_timezone_set('UTC');
define('TEMPFILE', getcwd() . DIRECTORY_SEPARATOR . uniqid());

//
$argk = array(
    '--task' => 1,
    '--prefix' => 1,
);

//
$argm = array();
$argi = null;
$argf = 0;

// Loop command-line arguments
foreach ($argv as $i => $arg) {
    if ($i == 0) {
        continue;
    } else if (isset($argk[$arg])) {
        $argi = $arg;
        $argf = $argk[$argi];
    } else if ($argf > 0) {
        $argm[$argi][] = $arg;
        $argf--;
    } else {
        $argm[] = $arg;
    }
}

foreach ($argk as $arg => $count) {
    if (isset($argm[$arg][0]) && (!$argm[$arg][0] || $argm[$arg][0][0] == '-')) {
        _error("Missing '{$arg}' option value");
    }
}

if (isset($argm['--task'][0]) && $argm['--task'][0]) {
    $_argv = _loadTask($argm['--task'][0], isset($argm['--prefix'][0]) ? $argm['--prefix'][0] : '');
    _args(count($_argv), $_argv);
} else {
    _args($argc, $argv);
}

// Inputs validation
foreach (['src', 'tgt'] as $key0) {
    foreach (['host'] as $key1) {
        if (empty($_ENV[$key0][$key1])) {
            _error('Missing option: '.$key0.'.'.$key1);
        }
    }
}

// Checking if source is ready
echo "Checking source (".$_ENV['src']['host']."): ";
$S = new IMAP($_ENV['src']);
echo "\n";

// Checking if target is ready
echo "Checking target (".$_ENV['tgt']['host']."): ";
$T = new IMAP($_ENV['tgt']);
echo "\n";

// Print-out current actions
echo "Actions: ";
echo $_ENV['wipe'] ? 'MOVE ' : '';
echo $_ENV['copy'] ? 'COPY ' : '';
echo $_ENV['once'] ? 'ONCE ' : '';
echo $_ENV['sync'] ? 'SYNC ' : '';
echo $_ENV['fake'] ? 'FAKE ' : '';
echo "\n";

//$tgt_path_list = $T->listPath();
//print_r($tgt_path_list);

$src_path_list = $S->listPath();
//print_r($src_path_list);
// exit;

$countSourcePath = count($src_path_list);
foreach ($src_path_list as $index => $path) {
    echo "\n";
    echo '[Path '.($index+1).'/'.$countSourcePath.'] '.json_encode($path, JSON_UNESCAPED_SLASHES)."\n";
    #echo "S: {$path['name']} = {$path['attribute']}\n";
    $nameOffset = strpos($path['name'], '}');
    $namePath =  $nameOffset > 0 ? substr($path['name'], $nameOffset + 1) : $path['name'];

    // Skip Logic Below
    if (_path_skip($path)) {
        echo "(ignored)\n";
        continue;
    }

    // Source Path
    if (!$_ENV['sync']) {
        echo "Opening source ... ";
        $S->setPath($path['name']);
        $src_path_stat = $S->pathStat();
        if (empty($src_path_stat['mail_count'])) {
            echo "(empty) (ignored)\n";
            continue;
        } else {
            echo "(" . $src_path_stat['mail_count'] . " messages)\n";
        }
    }

    // Target Path
    $tgt_path = _path_map($path['name']);
    echo "Mapping ($namePath) to (".$tgt_path.') on target ... '."\n";
    $T->setPath($tgt_path); // Creates if needed

    // Show info on Target
    echo 'Opening target ... ';
    $tgt_path_stat = $T->pathStat();

    //echo "T: {$tgt_path_stat['mail_count']} messages\n";
    if (empty($tgt_path_stat['mail_count'])) {
        echo "(empty)\n";
    } else {
        echo '('.$tgt_path_stat['mail_count']." messages)\n";
    }
    //echo "\n";

    // Build Index of Target
    $tgt_mail_list = array();
    for ($i=1;$i<=$tgt_path_stat['mail_count'];$i++) {
        $mail = $T->mailStat($i);
        $tgt_mail_list[ $mail['message_id'] ] = !empty($mail['subject']) ? $mail['subject'] : "[ No Subject ] Message $i";
    }

    //var_Dump(array_keys($tgt_mail_list));
    //die();
    // print_r($tgt_mail_list);

    if (!$_ENV['sync']) {
        $loopStep = -1;
        $loopStart = $src_path_stat['mail_count'];
        $loopStop = 1;
    } else {
        $loopStep = 1;
        $loopStart = 1;
        $loopStop = 0;
    }

    //for ($src_idx = 1; $src_idx <= $src_path_stat['mail_count']; $src_idx++) {
    //for ($src_idx = $src_path_stat['mail_count']; $src_idx >= 1; $src_idx = $src_idx--) {
    for ($src_idx = $loopStart; $src_idx >= $loopStop; $src_idx += $loopStep) {

        echo $src_idx."\n";
        $stat = $S->mailStat($src_idx);
        if (empty($stat['message_id'])) {
            echo "No more messages.\n";
            break;
        }

        $stat['answered'] = trim($stat['Answered']);
        $stat['unseen'] = trim($stat['Unseen']);

        if (empty($stat['subject'])) {
            $stat['subject'] = "[ No Subject ] Message $src_idx";
        }

        $time = @strtotime($stat['MailDate']);
        $datainfo = @strftime('%d-%m-%Y',$time);;

        echo '> #'.str_pad($src_idx.' '.substr($stat['subject'],0,40).' ('.$datainfo.')', 59);

        if (isset($_ENV['bfr']) && $_ENV['bfr']) {
            if ($time >= $_ENV['bfr']) {
                echo "[before skip]\n";
                continue;
            }
        }

        if (isset($_ENV['afr']) && $_ENV['afr']) {
            if ($time < $_ENV['afr']) {
                echo "[after skip]\n";
                continue;
            }
        }

        // print_r($stat['message_id']); exit;

        // il messaggio è già stato copiato
        if (array_key_exists(@$stat['message_id'], $tgt_mail_list)) {
            //echo "S:$src_idx Mail: {$stat['subject']} Copied Already\n";
            echo "(already copied)\n";
            $S->mailWipe($src_idx);
            continue;
        }

        // echo "S:$src_idx {$stat['subject']} ({$stat['MailDate']})\n   {$src_path_stat['path']} => ";
        if ($_ENV['fake']) {
            echo "\n";
            continue;
        }

        $S->mailGet($src_idx);
        $opts = array();
        if (empty($stat['unseen'])) $opts[] = '\Seen';
        if (!empty($stat['answered'])) {
            $opts[] = '\Answered';
        }

        $opts = implode(' ',$opts);
        $date = @strftime('%d-%b-%Y %H:%M:%S +0000',$time);

        ##
        if ($res = $T->mailPut(file_get_contents(TEMPFILE),$opts,$date)) {
            // echo "T: $res\n";
            $S->mailWipe($src_idx);
            //echo "{$tgt_path_stat['path']}\n";
            echo "(copied)\n";
        } else {
            echo ("(fail)\n");
            var_dump($res);
            die();
        }

        ##
        if ($_ENV['once']) {
            die("(!) Mail at once, recall.\n");
        }
    }
}

echo "\n";
echo "Close.\n";
$S->close();

class IMAP
{
    private $_c; // Connection Handle
    private $_c_host; // Server Part {}
    private $_c_base; // Base Path Requested
    private $_is_gmail = false;
    /**
    * Connect to an IMAP
     */
    function __construct($uri)
    {
        $this->_c = null;
        $this->_is_gmail = $uri['host'] == 'imap.gmail.com';
        $this->_c_host = sprintf('{%s', $uri['host']);

        if (!empty($uri['port'])) {
            $this->_c_host.= sprintf(':%d', $uri['port']);
        }

        switch (strtolower(@$uri['scheme'])) {
            case 'imap-ssl':
                $this->_c_host.= '/ssl';
                break;
            case 'imap-tls':
                $this->_c_host.= '/tls';
                break;
            case 'imap-novalidate-cert':
                $this->_c_host.= '/novalidate-cert';
                break;
            case 'imap-ssl-novalidate-cert':
                $this->_c_host.= '/ssl/novalidate-cert';
                break;
            default:
        }
        $this->_c_host.= '}';

        $this->_c_base = $this->_c_host;
        // Append Path?
        if (!empty($uri['path'])) {
            $x = ltrim($uri['path'],'/.');
            if (!empty($x)) {
                $this->_c_base.= $x;
            }
        }
        //echo "imap_open($this->_c_host)\n";
        imap_timeout(IMAP_OPENTIMEOUT,20);
        $this->_c = @imap_open($this->_c_host,$uri['user'],$uri['pass'], CL_EXPUNGE);
        $err = imap_errors();
        if (count($err) == 1 && $err[0] == 'SECURITY PROBLEM: insecure server advertised AUTH=PLAIN') {
            ## do nothing and continue
        } else if ($err) {
            if (count($err) < 3) {
                echo implode(", ", $err)."\n";
            } else {
                echo "\n";
                foreach($err as $e) {
                    echo "  - ".wordwrap($e, 60, "\n    ")."\n";
                }
            }
            exit;
        } else {
            echo "OK!";
        }
    }

    /**
    * List folders matching pattern
    * @param $pat * == all folders, % == folders at current level
     */
    function listPath($pat='*')
    {
        $ret = array();
        $list = imap_getmailboxes($this->_c, $this->_c_host,$pat);
        foreach ($list as $x) {
            $ret[] = array(
                'name' => $x->name,
                'attribute' => $x->attributes,
                'delimiter' => $x->delimiter,
            );
        }
        return $ret;
    }

    /**
     * Get a Message.
     *
     * @param $index
     * @return bool
     */
    function mailGet($index)
    {
        // return imap_body($this->_c,$i,FT_PEEK);
        return imap_savebody($this->_c, TEMPFILE, $index, null, FT_PEEK);
    }

    /**
    * Store a Message with proper date
     */
    function mailPut($mail,$opts,$date)
    {
        $stat = $this->pathStat();
        // print_r($stat);
        // $opts = '\\Draft'; // And Others?
        // $opts = null;
        // exit;
        $ret = imap_append($this->_c,$stat['check_path'],$mail,$opts,$date);
        if ($buf = imap_errors()) {
            die(print_r($buf,true));
        }

        return $ret;
    }

    /**
    * Message Info
     */
    function mailStat($i)
    {
        $head = imap_headerinfo($this->_c, $i);
        return (array) $head;
        // $stat = imap_fetch_overview($this->_c,$i);
        // return (array)$stat[0];
    }

    /**
    * Immediately Delete and Expunge the message
     */
    function mailWipe($i)
    {
        if ( $_ENV['wipe']) {
            $ret = imap_delete($this->_c, $i);
            $err = imap_errors();
            if ($err) {
                var_dump($err);
                die("mailWipe error #1!");
            }
            $ret = imap_expunge($this->_c);
            $err = imap_errors();
            if ($err) {
                var_dump($err);
                die("mailWipe error #2!");
            }
        }
    }

    /**
     * Sets the Current Mailfolder, Creates if Needed
     *
     * @param $p
     * @param bool $make
     * @return bool
     */
    function setPath($p, $make = false)
    {
        $p = $this->getFullPath($p);

        //echo "setPath($p);\n";
        $ret = imap_reopen($this->_c, $p, CL_EXPUNGE); // Always returns true :(
        $buf = imap_errors();
        if (empty($buf)) {
            return true;
        }

        $buf = implode(', ',$buf);

        if (preg_match('/NONEXISTENT/', $buf) ||
            preg_match('/Mailbox does not exist/', $buf) ||
            preg_match('/Mailbox doesn\'t exist/', $buf)
        ) {
            // Likley Couldn't Open on Gmail Side, So Create
            $ret = imap_createmailbox($this->_c, $p);
            $buf = imap_errors();
            if (empty($buf)) {
                imap_reopen($this->_c, $p);
            } else {
                die(print_r($buf,true)."\n\nFailed to Create setPath($p)\n");
            }
            $ret = imap_subscribe($this->_c,$p);
            $buf = imap_errors();
            if (empty($buf)) {
                // Reopen Again
                imap_reopen($this->_c,$p);
            } else {
                die(print_r($buf,true)."\n\nFailed to Subscribe setPath($p)\n");
            }
            return true;
        }

        die(print_r($buf,true)."\n\nFailed to Switch setPath($p)\n");
    }

    /**
     * Check if is a path name or a full path
     *
     * @param $path
     * @return bool
     */
    public function isFullPath($path)
    {
        return substr($path, 0, 1) == '{';
    }

    /**
     * @param $path
     * @return string
     */
    public function getFullPath($path)
    {
        if ($this->isFullPath($path)) {
            return $path;
        }

        echo 'Resolving ('.$path.') with ';

        if ($this->_is_gmail) {
            $path = $this->_c_host . trim($path, '/');
        } else {
            $pa = str_replace(array("/", "[Gmail]"), array(".", "Gmail"), $path);
            $pathName = trim($pa, '/.');
            if ($pathName == 'INBOX' && substr($this->_c_base, -6) == '}INBOX') {
                $path = $this->_c_base;
            } else {
                $path = $this->_c_base . '.' . $pathName;
            }
        }

        echo '('.$path.')'."\n";

        return $path;
    }

    /**
     * Returns Information about the current Path.
     */
    function pathStat()
    {
        $t0 = time();
        $res = imap_mailboxmsginfo($this->_c);
        $ret = array(
            'date' => $res->Date,
            'path' => $res->Mailbox,
            'mail_count' => $res->Nmsgs,
            'size' => $res->Size,
        );
        $t1 = time();
        $res = imap_check($this->_c);
        $t2 = time();
        //echo '(T:'.($t1 - $t0).';'.($t2 - $t1).') ';
        if ($buf = imap_errors()) {
            die(print_r($buf,true));
        }
        $ret['check_date'] = $res->Date;
        $ret['check_mail_count'] = $res->Nmsgs;
        $ret['check_path'] = $res->Mailbox;
        // $ret = array_merge($ret, $res);
        return $ret;
    }

    /**
     *
     */
    function close() {
        imap_close($this->_c, CL_EXPUNGE);
    }
}

/**
Process CLI Arguments
 */
function _args($argc, $argv)
{
    $_ENV['src'] = null;
    $_ENV['tgt'] = null;
    $_ENV['copy'] = false;
    $_ENV['fake'] = false;
    $_ENV['once'] = false;
    $_ENV['wipe'] = false;
    $_ENV['sync'] = false;

    for ($i = 1; $i < $argc; $i++) {
        switch ($argv[$i]) {
            case '--source':
            case '-s':
                $i++;
                if (!empty($argv[$i])) {
                    $_ENV['src'] = parse_url($argv[$i]);
                }
                break;
            case '--target':
            case '-t': // Destination
                $i++;
                if (!empty($argv[$i])) {
                    $_ENV['tgt'] = parse_url($argv[$i]);
                }
                break;
            case '--copy':
                // Given a Path to Copy To?
                /*
                $chk = $argv[$i+1];
                if (substr($chk,0,1)!='-') {
                    $_ENV['copy_path'] = $chk;
                    if (!is_dir($chk)) {
                        echo "Creating Copy Directory\n";
                        mkdir($chk,0755,true);
                    }
                    $i++;
                }*/
                $_ENV['copy'] = true;
                break;
            case '--fake':
                $_ENV['fake'] = true;
                break;
            case '--once':
                $_ENV['once'] = true;
                break;
            case '--sync':
                $_ENV['sync'] = true;
                break;
            case '--wipe':
                $_ENV['wipe'] = true;
                break;
            case '--before':
            case '-b':
                $i++;
                if (!empty($argv[$i])) {
                    $_ENV['bfr'] = strtotime($argv[$i]);
                }
                break;
            case '--after':
            case '-a':
                $i++;
                if (!empty($argv[$i])) {
                    $_ENV['afr'] = strtotime($argv[$i]);
                }
                break;
            default:
                echo "arg: {$argv[$i]}\n";
        }
    }

    if ((empty($_ENV['src']['path'])) || ($_ENV['src']['path']=='/')) {
        $_ENV['src']['path'] = '/INBOX';
    }

    if ((empty($_ENV['tgt']['path'])) || ($_ENV['tgt']['path']=='/')) {
        $_ENV['tgt']['path'] = '/INBOX';
    }

    $_ENV['src']['user'] = isset($_ENV['src']['user']) ? str_replace('__', ' ', $_ENV['src']['user']) : '';
    $_ENV['tgt']['user'] = isset($_ENV['tgt']['user']) ? str_replace('__', ' ', $_ENV['tgt']['user']) : '';
}

/**
@return mapped path name
 */
function _path_map($x)
{
    if (preg_match('/}(.+)$/', $x, $m)) {
        switch (strtolower($m[1])) {
            // case 'inbox':         return null;
            case 'deleted items': return '[Gmail]/Trash';
            case 'drafts':        return '[Gmail]/Drafts';
            case 'junk e-mail':   return '[Gmail]/Spam';
            case 'sent items':    return '[Gmail]/Sent Mail';
        }
        $x = str_replace('INBOX/', null, $m[1]);
    }

    return imap_utf7_encode($x);
}

/**
 *
 *
 * @param $path
 *
 * @return bool true if we should skip this path
 */
function _path_skip($path)
{
    if (($path['attribute'] & LATT_NOSELECT) == LATT_NOSELECT) {
        return true;
    }

    // All Mail, Trash, Starred have this attribute
    if ( ($path['attribute'] & 96) == 96) {
        return true;
    }

    // Skip by Pattern
    if (preg_match('/}(.+)$/', $path['name'], $m)) {
        switch (strtolower($m[1])) {
            case '[gmail]/all mail':
            case '[gmail]/sent mail':
            case '[gmail]/spam':
            case '[gmail]/starred':
                return true;
        }
    }

    // By First Folder Part of Name
    if (preg_match('/}([^\/]+)/', $path['name'], $m)) {
        switch (strtolower($m[1])) {
            // This bundle is from Exchange
            case 'journal':
            case 'notes':
            case 'outbox':
            case 'rss feeds':
            case 'sync issues':
                return true;
        }
    }

    return false;
}

/**
 *
 * @param $activity
 * @param $base
 * @return array
 */
function _loadTask($task, $prefix)
{
    $taskFile = $prefix.$task.(!pathinfo($task, PATHINFO_EXTENSION) ? '.json' : '');
    $json = _loadFile($taskFile);
    if (!@$json->source) {
        _error("(?) Source not declared: "._striplen($taskFile, 49));
    }
    if (!@$json->target) {
        _error("(?) target not declared: "._striplen($taskFile, 49));
    }

    $prefix = dirname($taskFile).'/';

    $sourceFile = $prefix.$json->source.(!pathinfo($json->source, PATHINFO_EXTENSION) ? '.json' : '');
    $sourceJson = _loadFile($sourceFile);

    $targetFile = $prefix.$json->target.(!pathinfo($json->target, PATHINFO_EXTENSION) ? '.json' : '');
    $targetJson = _loadFile($targetFile);

    $sourceLine = _composeLine($sourceJson);
    $targetLine = _composeLine($targetJson);

    $return = array(
        __FILE__,
        '--source',
        $sourceLine,
        '--target',
        $targetLine
    );

    if (isset($json->before) && $json->before) {
        $return[] = '--before';
        $return[] = $json->before;
    }

    if (isset($json->after) && $json->after) {
        $return[] = '--after';
        $return[] = $json->after;
    }

    $action = isset($json->action) ? $json->action : 'fake';

    switch ($action) {
        case 'copy':
            $return[] = '--copy';
            break;
        case 'copy-once':
            $return[] = '--copy';
            $return[] = '--once';
            break;
        case 'copy-sync':
            $return[] = '--copy';
            $return[] = '--sync';
            break;
        case 'move':
            $return[] = '--wipe';
            break;
        case 'move-once':
            $return[] = '--wipe';
            $return[] = '--once';
            break;
        case 'move-sync':
            $return[] = '--wipe';
            $return[] = '--sync';
            break;
        default:
            _error("(?) Invalid task '${action}' action on file "._striplen($taskFile, 45));
    }

    return $return;
}

/**
 * @param $json
 * @return string
 */
function _composeLine($json)
{
    //imap-novalidate-cert://${s_user}:${s_pass}@${s_host}/
    $protocol = isset($json->protocol) ? $json->protocol : 'imap';
    $line = $protocol . '://'
        . (isset($json->username) ? $json->username : 'a') . ':'
        . (isset($json->password) ? $json->password : 'a') . '@'
        . (isset($json->host) ? $json->host : 'a') . ':'
        . (isset($json->port) ? $json->port : '25') . '/'
        . (isset($json->path) ? $json->path : '');

    return $line;
}

/**
 * @param $file
 * @return mixed
 */
function _loadFile($file)
{
    if (!file_exists($file)) {
        _error("(?) File not found: "._striplen($file, 54));
    }

    $json = json_decode(file_get_contents($file));

    if (!$json || json_last_error()) {
        _error("(?) JSON syntax error: "._striplen($file, 51));
    }

    return $json;
}

/**
 * Print-out error message and exit.
 *
 * @param $message
 * @return string
 */
function _error($message)
{
    echo $message."\n";
    exit(1);
}

/**
 * @param $text
 * @param $len
 * @return string
 */
function _striplen($text, $len)
{
    if (strlen($text) > $len) {
        return '...' . substr($text, strlen($text) - $len);
    } else {
        return $text;
    }
}
