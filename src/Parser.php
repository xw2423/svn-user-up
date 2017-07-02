<?php
namespace SvnUserParser;

class Parser
{

    const COM_REPOURL = 'svn info 2>&1|grep "URL: svn://.*"|awk \'{print $2}\'';
    const COM_BASEURL = 'svn info 2>&1|grep "[^L]: svn://.*"|awk \'{print $2}\'';
    const COM_SVNLOG = 'svn log -v --xml -l %s "%s" 2>&1';
    const COM_SVNUP = 'svn up -r %s %s';

    const DOC = <<<DOC
Usage:
    cli.php USER [-r REV|--revision REV] [-l LIMIT|--limit LIMIT] [-e|--exec]

Options:
    -r REV, --revision REV      set svn update revision
    -l LIMIT, --limit LIMIT     set number of recent revision [default: 100]
    -e --exec                   execute command
    -h --help                   Show this screen.
DOC;

    private $_repoDir = '';
    private $_repoURL = '';
    private $_baseURL = '';
    private $_updated = array();

    public $user = '';
    public $limit = 100;
    public $revision = null;

    public static function log($msg, $exit = false, $br = true){
        echo $msg;
        if($br) echo "\n";
        if($exit) exit();
    }

    public static function parse(){
        $args = (new \Docopt\Handler)->handle(self::DOC);
        $parser = new Parser();
        $parser->user = $args['USER'];
        $parser->limit = $args['--limit'];
        $parser->revision = $args['--revision'];
        $parser->run($args['--exec']);
    }

    public function __construct($dir = ''){
        $this->_repoDir = $dir === ''?getcwd():$dir;
        $this->_repoUrl = exec(self::COM_REPOURL);
        $this->_baseUrl = exec(self::COM_BASEURL);
        if($this->_repoUrl == '')
            self::log($repoDir . ' is not a svn directory', true);
    }

    public function run($exec = false){
        $xmlraw = shell_exec(sprintf(self::COM_SVNLOG, $this->limit, $this->_repoDir));
        libxml_use_internal_errors(true);
        try{
            $xml = simplexml_load_string($xmlraw);
            if($xml === false)
                throw new Exception();
        }catch(Exception $e){
            self::log('load svn log error!');
        }

        $data = $this->_getLogData($xml);

        self::log(sprintf('%s "svn up" FOR %s:' . "\n", ($exec === true?'EXECUTE':'TEST'), $this->user));
        foreach($data as $v){
            self::log('REVISION:' . join('|', $v['info']));
            self::log(join("\n", array_map(function($e) use($exec, $v){
                return sprintf("=====>%s%s", $e, ($exec === true?('......' . $this->_svnUp($e, $v['info'][0])):''));
            }, $v['files'])) . "\n");
        }
    }

    private function _getLogData($xml){
        $data = [];
        $prefix = str_replace($this->_baseUrl, '', $this->_repoUrl);
        foreach($xml->logentry as $log){
            if($this->user === '' || $log->author == $this->user){
                if(!is_null($this->revision) && $this->revision != (string)$log['revision']) continue;
                $tmp = [
                    'info' => [
                        (string)$log['revision'],
                        date('m-d H:i:s', strtotime($log->date)),
                        explode("\n", (string)$log->msg)[0]
                    ]
                ];
                foreach($log->paths->path as $p){
                    $tmp['files'][] = str_replace($prefix . '/', '', (string)$p);
                }
                $data[] = $tmp;
            }
        }
        return $data;
    }

    private function _svnUp($file, $revision){
        if(isset($this->_updated[$file]))
            return $this->_updated[$file] . '!';
        return $this->_updated[$file] = exec(sprintf(self::COM_SVNUP, $revision, $file));
    }

}
