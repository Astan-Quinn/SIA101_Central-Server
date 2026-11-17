<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function central_replay_env(string $name): string
{
    static $values=null;
    if($values===null){$values=[];$path=dirname(__DIR__).'/.env';if(is_readable($path))foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$key,$value]=explode('=',$line,2);$key=trim($key);$value=trim($value);if(strlen($value)>=2&&(($value[0]==='"'&&str_ends_with($value,'"'))||($value[0]==="'"&&str_ends_with($value,"'"))))$value=substr($value,1,-1);$values[$key]=$value;}}
    $env=getenv($name);return trim($env!==false&&trim($env)!==''?$env:(string)($values[$name]??''));
}

function central_replay_request(string $url,string $key,?array $body=null): array
{
    $headers=['apikey: '.$key,'Accept: application/json','Content-Type: application/json'];
    if(!str_starts_with($key,'sb_secret_'))$headers[]='Authorization: Bearer '.$key;
    $ch=curl_init($url);$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
    if($body!==null){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}curl_setopt_array($ch,$opts);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
    if($raw===false)throw new RuntimeException('Central Supabase request failed: '.$error);
    if($status<200||$status>=300)throw new RuntimeException('Central Supabase returned HTTP '.$status.': '.substr((string)$raw,0,500));
    $decoded=json_decode($raw,true);if(json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('Central Supabase returned invalid JSON');
    return is_array($decoded)?$decoded:['value'=>$decoded];
}

try{
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required');
    $base=rtrim(central_replay_env('SUPABASE_URL'),'/');$key=central_replay_env('SUPABASE_SECRET_KEY');
    if(!str_starts_with($base,'https://')||$key==='')throw new RuntimeException('Central Supabase server credentials are missing');
    $events=central_replay_request($base.'/rest/v1/integration_events?source_system=eq.UtilityTrack&record_type=eq.utility_anomaly&select=event_id&order=occurred_at.asc&limit=1000',$key);
    $queued=0;$alreadyRouted=0;
    foreach($events as $event){$id=(string)($event['event_id']??'');if($id==='')continue;
        $result=central_replay_request($base.'/rest/v1/rpc/integration_replay_accepted_event',$key,['p_event_id'=>$id,'p_destination'=>'LifeCycleTrack']);
        $accepted=$result['value']??$result;
        if($accepted===true)$queued++;else $alreadyRouted++;
    }
    fwrite(STDOUT,sprintf("Found %d UtilityTrack anomaly event(s); queued %d for LifeCycleTrack; %d already routed.\n",count($events),$queued,$alreadyRouted));
}catch(Throwable $error){error_log('Central anomaly replay failed: '.$error->getMessage());fwrite(STDERR,'Central anomaly replay failed: '.$error->getMessage()."\n");exit(1);}
