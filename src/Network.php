<?php

namespace duncan3dc\Sonos;

use duncan3dc\Helpers\DiskCache;
use duncan3dc\DomParser\XmlParser;

class Network
{
    protected static $speakers = false;
    protected static $playlists = false;
    public static $cache = false;


    protected static function getDevices()
    {
        $ip = "239.255.255.250";
        $port = 1900;

        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($sock, getprotobyname("ip"), IP_MULTICAST_TTL, 2);

        $data = "M-SEARCH * HTTP/1.1\r\n";
        $data .= "HOST: " . $ip . ":reservedSSDPport\r\n";
        $data .= "MAN: ssdp:discover\r\n";
        $data .= "MX: 1\r\n";
        $data .= "ST: urn:schemas-upnp-org:device:ZonePlayer:1\r\n";

        socket_sendto($sock, $data, strlen($data), null, $ip, $port);

        $read = [$sock];
        $write = [];
        $except = [];
        $name = null;
        $port = null;
        $tmp = "";

        $response = "";
        while(socket_select($read, $write, $except, 1) && $read) {
            socket_recvfrom($sock, $tmp, 2048, null, $name, $port);
            $response .= $tmp;
        }

        $devices = [];
        foreach(explode("\r\n\r\n", $response) as $reply) {
            if(!$reply) {
                continue;
            }

            $data = [];
            foreach(explode("\r\n", $reply) as $line) {
                if(!$pos = strpos($line, ":")) {
                    continue;
                }
                $key = strtolower(substr($line, 0, $pos));
                $val = trim(substr($line, $pos + 1));
                $data[$key] = $val;
            }
            $devices[] = $data;
        }

        $return = [];
        $unique = [];
        foreach($devices as $device) {
            if(in_array($device["usn"], $unique)) {
                continue;
            }
            $url = parse_url($device["location"]);
            $ip = $url["host"];

            $return[] = $ip;
            $unique[] = $device["usn"];
        }

        return $return;
    }


    public static function getSpeakers()
    {
        if(is_array(static::$speakers)) {
            return static::$speakers;
        }

        if(static::$cache) {
            $devices = DiskCache::call("ip-addresses", function() {
                return static::getDevices();
            });
        } else {
            $devices = static::getDevices();
        }

        if(count($devices) < 1) {
            throw new \Exception("No devices found on the current network");
        }

        $speakers = [];
        foreach($devices as $ip) {
            $speakers[$ip] = new Speaker($ip);
        }

        $speaker = reset($speakers);
        $topology = $speaker->getXml("/status/topology");
        $players = $topology->getTag("ZonePlayers")->getTags("ZonePlayer");
        foreach($players as $player) {
            $attributes = $player->getAttributes();
            $ip = parse_url($attributes["location"])["host"];
            if(array_key_exists($ip, $speakers)) {
                $speakers[$ip]->setTopology($attributes);
            }
        }

        return static::$speakers = $speakers;
    }


    public static function getController()
    {
        $controllers = static::getControllers();
        return reset($controllers);
    }


    public static function getSpeakerByRoom($room)
    {
        $speakers = static::getSpeakers();
        foreach($speakers as $speaker) {
            if($speaker->room == $room) {
                return $speaker;
            }
        }

        throw new \Exception("No speaker found with the room name '" . $room . "'");
    }


    public static function getSpeakersByRoom($room)
    {
        $return = [];

        $speakers = static::getSpeakers();
        foreach($speakers as $controller) {
            if($controller->room == $room) {
                $return[] = $controller;
            }
        }

        if(count($return) < 1) {
            throw new \Exception("No speakers found with the room name '" . $room . "'");
        }

        return $return;
    }


    public static function getControllers()
    {
        $controllers = [];

        $speakers = static::getSpeakers();
        foreach($speakers as $speaker) {
            if(!$speaker->isCoordinator()) {
                continue;
            }
            $controllers[$speaker->ip] = new Controller($speaker);
        }

        return $controllers;
    }


    public static function getControllerByRoom($room)
    {
        $speaker = static::getSpeakerByRoom($room);
        $group = $speaker->getGroup();

        $controllers = static::getControllers();
        foreach($controllers as $controller) {
            if($controller->getGroup() == $group) {
                return $controller;
            }
        }

        throw new \Exception("No controller found with the room name '" . $room . "'");
    }


    public static function getPlaylists()
    {
        if(is_array(static::$playlists)) {
            return static::$playlists;
        }

        $controller = static::getController();

        $data = $controller->soap("ContentDirectory", "Browse", [
            "ObjectID"          =>  "SQ:",
            "BrowseFlag"        =>  "BrowseDirectChildren",
            "Filter"            =>  "",
            "StartingIndex"     =>  0,
            "RequestedCount"    =>  100,
            "SortCriteria"      =>  "",
        ]);
        $parser = new XmlParser($data["Result"]);

        $playlists = [];
        foreach($parser->getTags("container") as $container) {
            $playlists[] = new Playlist($container);
        }

        return static::$playlists = $playlists;
    }


    public static function getPlaylistByName($name)
    {
        $roughMatch = false;

        $playlists = static::getPlaylists();
        foreach($playlists as $playlist) {
            if($playlist->getName() == $name) {
                return $playlist;
            }
            if(strtolower($playlist->getName()) == strtolower($name)) {
                $roughMatch = $playlist;
            }
        }

        if($roughMatch) {
            return $roughMatch;
        }

        throw new \Exception("No playlist found with the name '" . $name . "'");
    }
}