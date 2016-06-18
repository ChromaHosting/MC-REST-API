<?php

namespace App\Http\Controllers;

use \App\Server;
use \Cache;
use \xPaw\MinecraftPing;
use \xPaw\MinecraftPingException;

class ServerController extends ApiController
{

    const MINECRAFT_PORT = 25565;
    const DEFAULT_BUNGEE_QUERY_PORT = 25577;

    public function ping($domain, $port = self::MINECRAFT_PORT)
    {
        $cached = Cache::get('server:' . $domain . ':' . $port);
        if ($cached !== NULL) {
            return collect($cached)->put('source', 'cache');
        }

        $unresolved_domain = $domain;
        $unresolved_port = $port;

        //try again with the resolved srv record
        $this->resolveMinecraftSRV($domain, $port);
        $cached = Cache::get('server:' . $domain . ':' . $port);
        if ($cached !== NULL) {
            return collect($cached)->put('source', 'cache');
        }

        try {
            $Query = new MinecraftPing($domain, $port);

            $data = $Query->Query();

            /* @var $server Server */
            $server = Server::firstOrNew(['address' => $domain, 'port' => $port]);
            $server->motd = $data['description'];
            $server->version = $data['version']['name'];
            $server->players = $data['players']['online'];
            $server->maxplayers = $data['players']['max'];
            $server->online = true;
            $server->ping = $this->pingDomain($domain, $port);
            $server->save();

            //orignal description
            $result = collect($server)->put('desc', $data['description']);
            if (isset($data['favicon'])) {
                $result->put('favicon', $data['favicon']);
            }

            Cache::put('server:' . $domain . ':' . $port, $result, env('CACHE_SERVER_LENGTH', 5));
            Cache::put('server:' . $unresolved_domain . ':' . $unresolved_port, $result, env('CACHE_SERVER_LENGTH', 5));

            return $result;
        } catch (MinecraftPingException $e) {
            $server = Server::firstOrNew(['address' => $domain, 'port' => $port]);
            $server->online = false;
            $result = collect($server);

            Cache::put('server:' . $domain . ':' . $port, $result, env('CACHE_SERVER_LENGTH', 5));
            Cache::put('server:' . $unresolved_domain . ':' . $unresolved_port, $result, env('CACHE_SERVER_LENGTH', 5));

            return $result;
        } finally {
            if (isset($Query)) {
                $Query->Close();
            }
        }
    }

    protected function resolveMinecraftSRV(&$host, &$port)
    {
        if (ip2long($host) !== FALSE) {
            //server address is an ip - we cannot resolve ip
            return;
        }

        $result = dns_get_record('_minecraft._tcp.' . $host, DNS_SRV);
        if (count($result) > 0) {
            if (isset($result[0]['target'])) {
                $host = $result[0]['target'];
            }

            if (isset($result[0]['port'])) {
                $port = $result[0]['port'];
            }
        }
    }

    protected function pingDomain($domain, $port) {
        //https://stackoverflow.com/questions/9841635/how-to-ping-a-server-port-with-php
        $starttime = microtime(true);
        $file = fsockopen($domain, $port, $errno, $errstr, 1);
        $stoptime = microtime(true);
        $status = 0;

        if (!$file)
            $status = -1;  // Site is down
        else {
            fclose($file);
            $status = ($stoptime - $starttime) * 1000;
            $status = floor($status);
        }

        return $status;
    }
}
