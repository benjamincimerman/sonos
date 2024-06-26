<?php

namespace duncan3dc\Sonos\Services;

use duncan3dc\DomParser\XmlParser;
use duncan3dc\Sonos\Exceptions\NotFoundException;
use duncan3dc\Sonos\Interfaces\ControllerInterface;
use duncan3dc\Sonos\Interfaces\Services\RadioInterface;
use duncan3dc\Sonos\Tracks\Stream;

/**
 * Handle radio streams using TuneIn.
 */
final class Radio implements RadioInterface
{
    /**
     * @var int The key for station types.
     */
    private const STATIONS = 2;

    /**
     * @var int The key for show types.
     */
    private const SHOWS = 1;

    /**
     * @var ControllerInterface $controller The Controller instance to send commands to.
     */
    protected $controller;


    /**
     * Create a new instance.
     *
     * @param ControllerInterface $controller A Controller instance to send commands to
     */
    public function __construct(ControllerInterface $controller)
    {
        $this->controller = $controller;
    }


    /**
     * Get the favourite radio shows/stations.
     *
     * @param int $type One of the class constants for either shows or stations
     *
     * @return Stream[]
     */
    protected function getFavourites(int $type): array
    {
        // https://sonos.svrooij.io/services/content-directory
        // Available actions:
        // - Music library (A)
        // - share(S:)
        // - Sonos playlists(SQ:)
        // - Sonos favorites(FV:2)
        // - radio stations(R:0/0)
        // - radio shows(R:0/1)
        // - queue(Q:)

        $items = [];

        $result = $this->controller->soap("ContentDirectory", "Browse", [
            "ObjectID"          =>  "FV/{$type}",
            "BrowseFlag"        =>  "BrowseDirectChildren",
            "Filter"            =>  "",
            "StartingIndex"     =>  0,
            "RequestedCount"    =>  100,
            "SortCriteria"      =>  "",
        ]);
        $parser = new XmlParser($result["Result"]);

        $tagName = ($type === self::STATIONS) ? "item" : "container";
        foreach ($parser->getTags($tagName) as $tag) {
            $name = $tag->getTag("title")->nodeValue;
            $uri = $tag->getTag("res")->nodeValue;
            $items[] = new Stream($uri, $name);
        }

        return $items;
    }


    /**
     * Get the favourite radio stations.
     *
     * @return Stream[]
     */
    public function getFavouriteStations(): array
    {
        return $this->getFavourites(self::STATIONS);
    }


    /**
     * Get the favourite radio station with the specified name.
     *
     * If no case-sensitive match is found it will return a case-insensitive match.
     *
     * @param string $name The name of the station
     *
     * @return Stream
     */
    public function getFavouriteStation(string $name): Stream
    {
        $roughMatch = false;

        $stations = $this->getFavouriteStations();
        foreach ($stations as $station) {
            if ($station->getTitle() === $name) {
                return $station;
            }
            if (strtolower($station->getTitle()) === strtolower($name)) {
                $roughMatch = $station;
            }
        }

        if ($roughMatch) {
            return $roughMatch;
        }

        throw new NotFoundException("Unable to find a radio station by the name '{$name}'");
    }


    /**
     * Get the favourite radio shows.
     *
     * @return Stream[]
     */
    public function getFavouriteShows(): array
    {
        return $this->getFavourites(self::SHOWS);
    }


    /**
     * Get the favourite radio show with the specified name.
     *
     * If no case-sensitive match is found it will return a case-insensitive match.
     *
     * @param string $name The name of the show
     *
     * @return Stream
     */
    public function getFavouriteShow(string $name): Stream
    {
        $roughMatch = false;

        $shows = $this->getFavouriteShows();
        foreach ($shows as $show) {
            if ($show->getTitle() === $name) {
                return $show;
            }
            if (strtolower($show->getTitle()) === strtolower($name)) {
                $roughMatch = $show;
            }
        }

        if ($roughMatch) {
            return $roughMatch;
        }

        throw new NotFoundException("Unable to find a radio show by the name '{$name}'");
    }
}
