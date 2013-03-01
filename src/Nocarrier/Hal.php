<?php
/**
 * This file is part of the Hal library
 *
 * (c) Ben Longden <ben@nocarrier.co.uk
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package Nocarrier
 */

namespace Nocarrier;

/**
 * The Hal document class
 *
 * @package Nocarrier
 * @author Ben Longden <ben@nocarrier.co.uk>
 */
class Hal
{
    /**
     * @var mixed
     */
    protected $uri;

    /**
     * The data for this resource. An associative array of key value pairs.
     * array(
     *     'price' => 30.00,
     *     'colour' => 'blue'
     * )
     *
     * @var array
     */
    protected $data;

    /**
     * @var array
     */
    protected $resources = array();

    /**
     * A collection of Nocarrier\HalLink objects keyed by the link relation to this
     * resource.
     * array(
     *     'next' => [HalLink]
     * )
     *
     * @var array
     */
    protected $links = array();

    /**
     * construct a new Hal object from an array of data. You can markup the
     * $data array with certain keys and values in order to affect the
     * generated JSON or XML documents if required to do so.
     *
     * '@' prefix on any array key will cause the value to be set as an
     * attribute on the XML element generated by the parent. i.e, array('x' =>
     * array('@href' => 'http://url')) will yield <x href='http://url'></x> in
     * the XML representation. The @ prefix will be stripped from the JSON
     * representation.
     *
     * Specifying the key 'value' will cause the value of this key to be set as
     * the value of the XML element instead of a child. i.e, array('x' =>
     * array('value' => 'example')) will yield <x>example</x> in the XML
     * representation. This will not affect the JSON representation.
     *
     * @param mixed $uri
     * @param array $data
     */
    public function __construct($uri = null, array $data = array())
    {
        $this->uri = $uri;
        $this->data = $data;
    }

    /**
     * Decode a application/hal+json document into a Nocarrier\Hal object
     *
     * @param string $text
     * @static
     * @access public
     * @return Nocarrier\Hal
     */
    public static function fromJson($text)
    {
        $data = json_decode($text, true);
        $uri = $data['_links']['self']['href'];
        unset ($data['_links']['self']);

        $links = $data['_links'];
        unset ($data['_links']);

        $embedded = isset($data['_embedded']) ? $data['_embedded'] : array();
        unset ($data['_embedded']);

        $hal = new Hal($uri, $data);
        foreach ($links as $rel => $link) {
            $hal->addLink($rel, $link['href'], $link['title']);
        }
        return $hal;
    }

    /**
     * Decode a application/hal+xml document into a Nocarrier\Hal object
     *
     * @param string $text
     * @static
     * @access public
     * @return Nocarrier\Hal
     */
    public static function fromXml($text)
    {
        $data = new \SimpleXMLElement($text);
        $children = $data->children();
        $links = clone $children->link;
        unset ($children->link);

        $embedded = clone $children->resource;
        unset ($children->resource);

        $hal = new Hal($data->attributes()->href, (array)$children);
        foreach ($links as $link) {
            $hal->addLink((string)$link->attributes()->rel, (string)$link->attributes()->href, (string)$link->attributes()->title);
        }

        return $hal;
    }

    /**
     * Add a link to the resource, identified by $rel, located at $uri, with an
     * optional $title
     *
     * @param string $rel
     * @param string $uri
     * @param string $title
     * @param array $attributes Other attributes, as defined by HAL spec and RFC 5988
     * @return Hal
     *
     */
    public function addLink($rel, $uri, $title = null, array $attributes = array())
    {
        $this->links[$rel][] = new HalLink($uri, $title, $attributes);
        return $this;
    }

    /**
     * Add an embedded resource, identified by $rel and represented by $resource.
     *
     * @param string $rel
     * @param Hal $resource
     */
    public function addResource($rel, Hal $resource)
    {
        $this->resources[$rel][] = $resource;
        return $this;
    }

    /**
     * Return an array of data (key => value pairs) representing this resource
     *
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Return an array of Nocarrier\HalLink objects representing resources
     * related to this one.
     *
     * @return array
     */
    public function getLinks()
    {
        return $this->links;
    }

    /**
     * Lookup and return an array of HalLink objects for a given relation.
     * Will also resolve CURIE rels if required.
     *
     * @param string $rel The link relation required
     * @return array|false
     */
    public function getLink($rel)
    {
        if (array_key_exists($rel, $this->links)) {
            return $this->links[$rel];
        }

        // this might be a curie link
        if (array_key_exists('curie', $this->links)) {
            foreach ($this->links['curie'] as $link) {
                $prefix = strstr($link->getUri(), '{rel}', true);
                if (strpos($rel, $prefix) === 0) {
                    // looks like it is
                    $shortrel = substr($rel, strlen($prefix));
                    $curie = "{$link->getAttributes()['name']}:$shortrel";
                    if (array_key_exists($curie, $this->links)) {
                        return $this->links[$curie];
                    }
                }
            }
        }

        return false;
    }

    /**
     * Return an array of Nocarrier\Hal objected embedded in this one.
     *
     * @return array
     */
    public function getResources()
    {
        return $this->resources;
    }

    /**
     * Get resource's URI
     *
     * @return mixed
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Return the current object in a application/hal+json format (links and resources)
     *
     * @param bool $pretty Enable pretty-printing
     * @return string
     */
    public function asJson($pretty=false)
    {
        $renderer = new HalJsonRenderer();
        return $renderer->render($this, $pretty);
    }

    /**
     * Return the current object in a application/hal+xml format (links and resources)
     *
     * @param bool $pretty Enable pretty-printing
     * @return string
     */
    public function asXml($pretty=false)
    {
        $renderer = new HalXmlRenderer();
        return $renderer->render($this, $pretty);
    }

    /**
     * Create a CURIE link template, used for abbreviating custom link 
     * relations.
     *
     * e.g,
     * $hal->addCurie('acme', 'http://.../rels/{rel}');
     * $hal->addLink('acme:test', 'http://.../test');
     *
     * @param name string
     * @param uri string
     * @return Hal
     */
    public function addCurie($name, $uri)
    {
        return $this->addLink('curie', $uri, null, array('name' => $name, 'templated' => true));
    }
}
