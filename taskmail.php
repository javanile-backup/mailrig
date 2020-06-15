<?php
/**
 * Mailstack
 *
 * the only tool managing email boxes.
 */

error_reporting(E_ALL);
date_default_timezone_set('UTC');
define('TEMPFILE', getcwd() . DIRECTORY_SEPARATOR . uniqid());

//
$argk = array(
    '--base' => 1,
    '--task' => 1,
);

//
$argm = array();
$argi = null;
$argf = 0;

// Loop command-line arguments
foreach($argv as $i => $arg) {
    if ($i != 0) {
        if (isset($argk[$arg])) {
            $argi = $arg;
            $argf = $argk[$argi];
        } else if ($argf > 0) {
            $argm[$argi][] = $arg;
            $argf--;
        } else {
            $argm[] = $arg;
        }
    }
}

//echo "--\n\n\n";

if ($argm['--task'][0]) {
    $_argv = _loadActivity($argm['--task'][0], @$argm['--base'][0]);
    _args(count($_argv), $_argv);
} else {
    _args($argc,$argv);
}

#print_r($_ENV);
#die();

echo "(!) Connecting source: ";
$S = new IMAP($_ENV['src']);
echo "\n";

echo "(!) Connecting target: ";
$T = new IMAP($_ENV['tgt']);
echo "\n";

echo "(!) Actions: ";
echo "WIPE =>".($_ENV['wipe']?'yes':'no')." ";
echo "COPY =>".($_ENV['copy']?'yes':'no')." ";
echo "ONCE =>".($_ENV['once']?'yes':'no')." ";
echo "FAKE =>".($_ENV['fake']?'yes':'no');
echo "\n";

//$tgt_path_list = $T->listPath();
//print_r($tgt_path_list);

$src_path_list = $S->listPath();
//print_r($src_path_list);
// exit;

foreach ($src_path_list as $path) {

    // var_dump($path);
    //echo "S: {$path['name']} = {$path['attribute']}\n";
    $nameOffset = strpos($path['name'],'}');
    $namePath =  $nameOffset > 0 ? substr($path['name'],$nameOffset+1) : $path['name'];

    //
    echo " -> Source path: ".str_pad($namePath, 47);

    // Skip Logic Below
    if (_path_skip($path)) {
        echo "[skip]\n";
        continue;
    }

    // Source Path
    $S->setPath($path['name']);
    $src_path_stat = $S->pathStat();

    // print_r($src_path_stat);
    if (empty($src_path_stat['mail_count'])) {
        echo "[skip empty]\n";
        continue;
    } else {
        echo "[".$src_path_stat['mail_count']." messages]\n";
    }
    //echo "S: {$src_path_stat['mail_count']} messages\n";

    // Target Path
    $tgt_path = _path_map($path['name']);
    echo " -> Target path: ".str_pad($tgt_path, 47);
    $T->setPath($tgt_path); // Creates if needed

    // Show info on Target
    $tgt_path_stat = $T->pathStat();
    //echo "T: {$tgt_path_stat['mail_count']} messages\n";
    if (empty($tgt_path_stat['mail_count'])) {
        echo "[empty]\n";
    } else {
        echo "[".$tgt_path_stat['mail_count']." messages]\n";
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

    // for ($src_idx=1;$src_idx<=$src_path_stat['mail_count'];$src_idx++) {
    for ($src_idx=$src_path_stat['mail_count'];$src_idx>=1;$src_idx--) {

        $stat = $S->mailStat($src_idx);
        $stat['answered'] = trim($stat['Answered']);
        $stat['unseen'] = trim($stat['Unseen']);

        if (empty($stat['subject'])) {
            $stat['subject'] = "[ No Subject ] Message $src_idx";
        }

        $time = @strtotime($stat['MailDate']);
        $datainfo = @strftime('%d-%m-%Y',$time);;

        echo '  - #'.str_pad($src_idx.' '.substr($stat['subject'],0,40).' ('.$datainfo.')', 59);

        ##
        if (isset($_ENV['bfr']) && $_ENV['bfr']) {
            if ($time >= $_ENV['bfr']) {
                echo "[before skip]\n";
                continue;
            }
        }

        ##
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
            echo "[copied already]\n";
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
            echo "[copied now]\n";
        } else {
            echo ("[fail]\n");
            var_dump($res);
            die();
        }

        ##
        if ($_ENV['once']) {
            die("(!) Mail at once, recall.\n");
        }
    }
}

echo "(!) Close.\n";
$S->close();

class IMAP
{
    private $_c; // Connection Handle
    private $_c_host; // Server Part {}
    private $_c_base; // Base Path Requested
    private $_is_gmail = false;
    /**
    Connect to an IMAP
     */
    function __construct($uri)
    {
        $this->_c = null;
        $this->_is_gmail = $uri['host'] == 'imap.gmail.com';
        $this->_c_host = sprintf('{%s',$uri['host']);
        if (!empty($uri['port'])) {
            $this->_c_host.= sprintf(':%d',$uri['port']);
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
        if (count($err)==1 && $err[0] == 'SECURITY PROBLEM: insecure server advertised AUTH=PLAIN') {
            ## do nothig and continue
        } else if ($err) {
            if (count($err)<3) {
                echo implode(", ",$err)."\n";
            } else {
                echo "\n";
                foreach($err as $e) {
                    echo "  - ".wordwrap($e,60,"\n    ")."\n";
                }
            }
            exit;
        } else {
            echo "success!";
        }
    }

    /**
    List folders matching pattern
    @param $pat * == all folders, % == folders at current level
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
     */
    function mailGet($index)
    {
        // return imap_body($this->_c,$i,FT_PEEK);
        return imap_savebody($this->_c, TEMPFILE, $index, null, FT_PEEK);
    }

    /**
    Store a Message with proper date
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
    Message Info
     */
    function mailStat($i)
    {
        $head = imap_headerinfo($this->_c, $i);
        return (array) $head;
        // $stat = imap_fetch_overview($this->_c,$i);
        // return (array)$stat[0];
    }

    /**
    Immediately Delete and Expunge the message
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
    Sets the Current Mailfolder, Creates if Needed
     */
    function setPath($p,$make=false)
    {
        // echo "setPath($p);\n";
        if (substr($p,0,1)!='{') {
            if ($this->_is_gmail) {
                $p = $this->_c_host . trim($p,'/');
            } else {
                $pa = str_replace(array("/","[Gmail]"),array(".","Gmail"),$p);
                $p = $this->_c_base . '.' .trim($pa,'/.');
            }
        }

        //echo "setPath($p);\n";

        $ret = imap_reopen($this->_c, $p, CL_EXPUNGE); // Always returns true :(
        $buf = imap_errors();
        if (empty($buf)) {
            return true;
        }

        $buf = implode(', ',$buf);
        if (preg_match('/NONEXISTENT/',$buf) ||
            preg_match('/Mailbox does not exist/',$buf)) {
            // Likley Couldn't Open on Gmail Side, So Create
            $ret = imap_createmailbox($this->_c,$p);
            $buf = imap_errors();
            if (empty($buf)) {
                imap_reopen($this->_c,$p);
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
     * Returns Information about the current Path.
     */
    function pathStat()
    {
        $res = imap_mailboxmsginfo($this->_c);
        $ret = array(
            'date' => $res->Date,
            'path' => $res->Mailbox,
            'mail_count' => $res->Nmsgs,
            'size' => $res->Size,
        );
        $res = imap_check($this->_c);
        if ($buf = imap_errors()) {
            die(print_r($buf,true));
        }
        $ret['check_date'] = $res->Date;
        $ret['check_mail_count'] = $res->Nmsgs;
        $ret['check_path'] = $res->Mailbox;
        // $ret = array_merge($ret,$res);
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

    $_ENV['src']['user'] = str_replace('__', ' ', $_ENV['src']['user']);
    $_ENV['tgt']['user'] = str_replace('__', ' ', $_ENV['tgt']['user']);
}

/**
@return mapped path name
 */
function _path_map($x)
{
    if (preg_match('/}(.+)$/',$x,$m)) {
        switch (strtolower($m[1])) {
            // case 'inbox':         return null;
            case 'deleted items': return '[Gmail]/Trash';
            case 'drafts':        return '[Gmail]/Drafts';
            case 'junk e-mail':   return '[Gmail]/Spam';
            case 'sent items':    return '[Gmail]/Sent Mail';
        }
        $x = str_replace('INBOX/',null,$m[1]);
    }
    return imap_utf7_encode ( $x );
}

/**
@return true if we should skip this path
 */
function _path_skip($path)
{
    if ( ($path['attribute'] & LATT_NOSELECT) == LATT_NOSELECT) {
        return true;
    }
    // All Mail, Trash, Starred have this attribute
    if ( ($path['attribute'] & 96) == 96) {
        return true;
    }

    // Skip by Pattern
    if (preg_match('/}(.+)$/',$path['name'],$m)) {
        switch (strtolower($m[1])) {
            case '[gmail]/all mail':
            case '[gmail]/sent mail':
            case '[gmail]/spam':
            case '[gmail]/starred':
                return true;
        }
    }

    // By First Folder Part of Name
    if (preg_match('/}([^\/]+)/',$path['name'],$m)) {
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

##
function _loadActivity($activity, $base) {

    ##
    $file = $base.'/activities/'.$activity.'.json';

    ##
    $json = _loadFile($file);

    ##
    if (!@$json->source) {
        die("(?) source not declared: "._striplen($file,49)."\n");
    }

    ##
    $sourceFile = $base.'/accounts/'.$json->source.'.json';

    ##
    $sourceJson = _loadFile($sourceFile);

    ##
    if (!@$json->target) {
        die("(?) target not declared: "._striplen($file,49)."\n");
    }

    ##
    $targetFile = $base.'/accounts/'.$json->target.'.json';

    ##
    $targetJson = _loadFile($targetFile);

    ##
    $sourceLine = _composeLine($sourceJson);

    ##
    $targetLine = _composeLine($targetJson);

    ##
    $return = array(
        __FILE__,
        '--source',
        $sourceLine,
        '--target',
        $targetLine
    );

    ##
    if (isset($json->before) && $json->before) {
        $return[] = '--before';
        $return[] = $json->before;
    }

    ##
    if (isset($json->after) && $json->after) {
        $return[] = '--after';
        $return[] = $json->after;
    }

    ##
    $action = isset($json->action) ? $json->action : 'copy';

    ##
    switch ($action) {

        case 'copy':
            $return[] = '--copy';
            break;

        case 'copy-once':
            $return[] = '--copy';
            $return[] = '--once';
            break;

        case 'move-once':
            $return[] = '--wipe';
            $return[] = '--once';
            break;

        case 'move':
            $return[] = '--wipe';
            break;

        default:
            die("(?) invalid declared action: "._striplen($file,45)."\n");
    }

    ##
    return $return;
}

/**
 *
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
        die("(?) file not found: "._striplen($file, 54)."\n");
    }

    $json = json_decode(file_get_contents($file));

    if (json_last_error()) {
        die("(?) json syntax error: "._striplen($file, 51)."\n");
    }

    return $json;
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
