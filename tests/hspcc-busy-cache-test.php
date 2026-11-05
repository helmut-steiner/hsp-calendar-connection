<?php
if (!function_exists('__')) { function __($text, $domain = '') { return $text; } }
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Offline policy tests: synthetic calendar only, no WordPress/database/network.
define('ABSPATH', __DIR__ . '/');
class WP_Error { private $code; public function __construct($code, $message = '') {$this->code=$code;} public function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
$GLOBALS['cache']=[];$GLOBALS['options']=[];
function get_transient($key){return $GLOBALS['cache'][$key]??false;}
function set_transient($key,$v,$ttl){$GLOBALS['cache'][$key]=$v;return true;}
function update_option($key,$v,$autoload=false){$GLOBALS['options'][$key]=$v;return true;}
class HSP_Calendar_Connection {
    const HEALTH='synthetic_health';
    public static $identity='calendar-a'; public static $error=false; public static $calls=[];
    public static $provider=1;
    public static $employeeErrors=[]; public static $employeeOffsets=[]; public static $employeeCalls=[];
    public static function provider_id(){return self::$provider;}
    public static function with_employee($id,callable $callback){$old=self::$provider;self::$provider=$id;try{return $callback();}finally{self::$provider=$old;}}
    public static function option_name($key){return self::$provider===1?$key:$key.'_employee_'.self::$provider;}
    public static function set_private_option($key,$value){return update_option(self::option_name($key),$value,false);}
    public static function connection_identity(){return self::$identity;}
    public static function source_keys(){return ['source-a'];}
    public static function source_identity($key){return self::$identity;}
    public static function report($s,$e){
        self::$calls[]=[$s,$e];
        self::$employeeCalls[]=[self::$provider,$s,$e];
        if(self::$error||!empty(self::$employeeErrors[self::$provider])){return new WP_Error('http_503');}
        $offset=self::$employeeOffsets[self::$provider]??60;
        $start=$s+$offset;$end=min($start+60,$e);
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Synthetic private marker//EN\r\nBEGIN:VFREEBUSY\r\nDTSTAMP:20261030T120000Z\r\nFREEBUSY:".gmdate('Ymd\THis\Z',$start).'/'.gmdate('Ymd\THis\Z',$end)."\r\nEND:VFREEBUSY\r\nEND:VCALENDAR\r\n";
    }
}
$base=dirname(__DIR__).'/includes/';
require $base.'class-hspcc-free-busy.php';require $base.'class-hspcc-busy-time.php';
$checks=0;
function assert_policy($v,$m){++$GLOBALS['checks'];if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function clear_memory(){(new ReflectionProperty(HSPCC_Busy_Time::class,'memory'))->setValue(null,[]);}
$start=strtotime('2027-01-02 00:00:00 UTC');$end=$start+86400;
$a=HSPCC_Busy_Time::intervals($start,$end);
assert_policy($a===[[$start+60,$start+120]],'initial busy periods');
$b=HSPCC_Busy_Time::intervals($start,$end);
assert_policy($b===$a&&count(HSP_Calendar_Connection::$calls)===1,'display reuses fresh cache');
HSP_Calendar_Connection::$error=true;
$c=HSPCC_Busy_Time::intervals($start,$end,true);
assert_policy(is_wp_error($c)&&count(HSP_Calendar_Connection::$calls)===2,'save bypasses fresh display cache and rejects outage');
clear_memory();foreach($GLOBALS['cache'] as &$entry){$entry['checkedAt']=time()-61;}unset($entry);
assert_policy(is_wp_error(HSPCC_Busy_Time::intervals($start,$end)),'stale cache does not hide outage');
HSP_Calendar_Connection::$error=false;$GLOBALS['cache']=[];clear_memory();
HSPCC_Busy_Time::intervals($start,$end);
HSP_Calendar_Connection::$identity='calendar-b';HSP_Calendar_Connection::$error=true;clear_memory();
assert_policy(is_wp_error(HSPCC_Busy_Time::intervals($start,$end)),'calendar change invalidates old cache');
HSP_Calendar_Connection::$identity='calendar-a';HSP_Calendar_Connection::$error=false;clear_memory();
foreach($GLOBALS['cache'] as &$entry){$entry['end']--;}unset($entry);
$before=count(HSP_Calendar_Connection::$calls);HSPCC_Busy_Time::intervals($start,$end);
assert_policy(count(HSP_Calendar_Connection::$calls)===$before+1,'incomplete coverage forces refresh');
$GLOBALS['cache']=[];clear_memory();HSP_Calendar_Connection::$calls=[];
$d=HSPCC_Busy_Time::intervals($start,$start+32*86400,true);
assert_policy(!is_wp_error($d)&&count(HSP_Calendar_Connection::$calls)===2,'long ranges split');
foreach(HSP_Calendar_Connection::$calls as $call){assert_policy($call[1]-$call[0]<=31*86400,'each REPORT bounded to 31 days');}
assert_policy(HSP_Calendar_Connection::$calls[0][1]===HSP_Calendar_Connection::$calls[1][0],'chunks have no coverage gap');
assert_policy(is_wp_error(HSPCC_Busy_Time::intervals($start,$start+368*86400)),'unbounded query rejected');
assert_policy(!str_contains(json_encode($GLOBALS['cache']),'Synthetic private marker'),'raw response not cached');
HSP_Calendar_Connection::$identity='';
assert_policy(is_wp_error(HSPCC_Busy_Time::intervals($start,$end)),'missing selection fails closed');

HSP_Calendar_Connection::$identity='calendar-a';HSP_Calendar_Connection::$error=false;
$GLOBALS['cache']=[];clear_memory();HSP_Calendar_Connection::$calls=[];
$providerOne=HSPCC_Busy_Time::intervals($start,$end);
HSP_Calendar_Connection::$provider=7;HSP_Calendar_Connection::$error=true;clear_memory();
assert_policy(is_wp_error(HSPCC_Busy_Time::intervals($start,$end))&&count(HSP_Calendar_Connection::$calls)===2,'employee7 cannot reuse employee1 cache even under same source identity');
HSP_Calendar_Connection::$error=false;
$providerSeven=HSPCC_Busy_Time::intervals($start,$end);
assert_policy($providerSeven===$providerOne&&count($GLOBALS['cache'])===2,'employee7 has its own validated cache namespace');
HSP_Calendar_Connection::$provider=1;HSP_Calendar_Connection::$error=true;clear_memory();
assert_policy(HSPCC_Busy_Time::intervals($start,$end)===$providerOne&&count(HSP_Calendar_Connection::$calls)===3,'employee1 retains only its own fresh cache');
HSP_Calendar_Connection::$provider=0;clear_memory();
assert_policy(is_wp_error(HSPCC_Busy_Time::intervals($start,$end))&&count(HSP_Calendar_Connection::$calls)===3,'unset employee refuses before cache or NAS access');
HSP_Calendar_Connection::$provider=1;HSP_Calendar_Connection::$error=false;
$GLOBALS['cache']=[];$GLOBALS['options']=[];clear_memory();HSP_Calendar_Connection::$calls=[];
HSP_Calendar_Connection::$employeeCalls=[];HSP_Calendar_Connection::$employeeOffsets=[1=>60,7=>600];
$one=HSP_Calendar_Connection::with_employee(1,fn()=>HSPCC_Busy_Time::intervals($start,$end));
$seven=HSP_Calendar_Connection::with_employee(7,fn()=>HSPCC_Busy_Time::intervals($start,$end));
assert_policy($one===[[$start+60,$start+120]]&&$seven===[[$start+600,$start+660]],'two employees independently read different periods even under same source identity');
assert_policy(array_column(HSP_Calendar_Connection::$employeeCalls,0)===[1,7],'reports execute under their canonical employee context');
assert_policy(HSP_Calendar_Connection::$provider===1,'successful independent reads restore context');
assert_policy(count($GLOBALS['cache'])===2,'two employees cannot share a display-cache key');
assert_policy(!empty($GLOBALS['options']['synthetic_health']['ok'])&&!empty($GLOBALS['options']['synthetic_health_employee_7']['ok']),'health is stored under separate employee option namespaces');
HSP_Calendar_Connection::$employeeErrors=[7=>true];
$failedSeven=HSP_Calendar_Connection::with_employee(7,fn()=>HSPCC_Busy_Time::intervals($start,$end,true));
assert_policy(is_wp_error($failedSeven),'employee7 fresh save refuses its unavailable feed');
assert_policy(HSP_Calendar_Connection::$provider===1,'failed independent feed restores context');
assert_policy($GLOBALS['options']['synthetic_health']['ok']===true&&$GLOBALS['options']['synthetic_health_employee_7']['ok']===false,'employee7 failure does not overwrite employee1 health');
$before=count(HSP_Calendar_Connection::$calls);
assert_policy(HSP_Calendar_Connection::with_employee(1,fn()=>HSPCC_Busy_Time::intervals($start,$end))===$one&&count(HSP_Calendar_Connection::$calls)===$before,'employee1 remains independently cached during employee7 outage');
HSP_Calendar_Connection::$employeeErrors=[1=>true];
$failedOne=HSP_Calendar_Connection::with_employee(1,fn()=>HSPCC_Busy_Time::intervals($start,$end,true));
$freshSeven=HSP_Calendar_Connection::with_employee(7,fn()=>HSPCC_Busy_Time::intervals($start,$end,true));
assert_policy(is_wp_error($failedOne)&&$freshSeven===$seven,'failure of employee1 cannot close employee7 availability');
assert_policy($GLOBALS['options']['synthetic_health']['ok']===false&&$GLOBALS['options']['synthetic_health_employee_7']['ok']===true,'independent recovery updates only its own health');
assert_policy(HSP_Calendar_Connection::$provider===1,'all fresh reads restore previous employee context');
echo 'PASS: '.$checks." busy/cache checks including independent employee feeds, cached periods, fresh failures and scoped health; offline stubs only.\n";
