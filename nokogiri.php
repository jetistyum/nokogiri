<?php
/*
 * This file is part of the zero package.
 * Copyright (c) 2012 olamedia <olamedia@gmail.com>
 *
 * This source code is release under the MIT License.
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Simple HTML parser
 *
 * @author olamedia <olamedia@gmail.com>
 */
class nokogiri implements IteratorAggregate
{
    const regexp = "/(?P<tag>[a-z0-9]+)?(\[(?P<attr>\S+)(=(?P<value>[^\]]+))?\])?(#(?P<id>[^\s:>#\.]+))?(\.(?P<class>[^\s:>#\.]+))?(:(?P<pseudo>(first|last|nth)-child)(\((?P<expr>[^\)]+)\))?)?\s*(?P<rel>>)?/isS";
    
    protected $_source = '';
    
    /**
     * @var DOMDocument
     */
    protected $_dom = null;
    
    /**
     * @var DOMDocument
     */
    protected $_tempDom = null;
    
    /**
     * @var DOMXpath
     * */
    protected $_xpath = null;
    
    /**
     * @var LibxmlError[]
     */
    protected $_libxmlErrors = null;
    
    protected static $_compiledXpath = array();
    
    public function __construct($htmlString = '')
    {
        $this->loadHtml($htmlString);
    }
    
    public static function init($htmlString = '')
    {
        return new self($htmlString);
    }
    
    public function getRegexp()
    {
        $tag = "(?P<tag>[a-z0-9]+)?";
        $attr = "(\[(?P<attr>\S+)=(?P<value>[^\]]+)\])?";
        $id = "(#(?P<id>[^\s:>#\.]+))?";
        $class = "(\.(?P<class>[^\s:>#\.]+))?";
        $child = "(first|last|nth)-child";
        $expr = "(\((?P<expr>[^\)]+)\))";
        $pseudo = "(:(?P<pseudo>". $child .")". $expr ."?)?";
        
        $rel = "\s*(?P<rel>>)?";
        
        $regexp = "/". $tag . $attr . $id . $class . $pseudo . $rel ."/isS";
        
        return $regexp;
    }
    
    public static function fromHtml($htmlString)
    {
        return self::init()->loadHtml($htmlString);
    }
    
    public static function fromHtmlNoCharset($htmlString)
    {
        return self::init()->loadHtmlNoCharset($htmlString);
    }
    
    public static function fromDom($dom)
    {
        return self::init()->loadDom($dom);
    }
    
    public function loadDom($dom)
    {
        $this->_dom = $dom;
        
        return $this;
    }
    
    public function loadHtmlNoCharset($htmlString = '')
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        
        $dom->preserveWhiteSpace = false;
        
        if (strlen($htmlString)) {
            libxml_use_internal_errors(true);
            
            $this->_libxmlErrors = null;
            
            $dom->loadHTML('<?xml encoding="UTF-8">'. $htmlString);
            
            // dirty fix
            foreach ($dom->childNodes as $item) {
                if ($item->nodeType == XML_PI_NODE) {
                    $dom->removeChild($item); // remove hack
                    break;
                }
            }
            
            $dom->encoding = 'UTF-8'; // insert proper
            
            $this->_libxmlErrors = libxml_get_errors();
            
            libxml_clear_errors();
        }
        
        $this->loadDom($dom);
        
        return $this;
    }
    
    public function loadHtml($htmlString = '')
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        
        $dom->preserveWhiteSpace = false;
        
        if (strlen($htmlString)) {
            libxml_use_internal_errors(true);
            
            $this->_libxmlErrors = null;
            
            $dom->loadHTML($htmlString);
            
            $this->_libxmlErrors = libxml_get_errors();
            
            libxml_clear_errors();
        }
        
        $this->loadDom($dom);
        
        return $this;
    }
    
    public function getErrors()
    {
        return $this->_libxmlErrors;
    }
    
    public function __invoke($expression)
    {
        return $this->get($expression);
    }
    
    public function get($expression, $compile = true)
    {
        return $this->getElements($this->getXpathSubquery($expression, false, $compile));
    }
    
    public function query($expression)
    {
        return $this->getElements($expression);
    }
    
    public function getDom($asIs = false)
    {
        if ($asIs) {
            return $this->_dom;
        }
        
        if ($this->_dom instanceof \DOMDocument)
        {
                return $this->_dom;
        }
        else if ($this->_dom instanceof \DOMNodeList || $this->_dom instanceof \DOMElement)
        {
            if ($this->_tempDom === null) {
                $this->_tempDom = new \DOMDocument('1.0', 'UTF-8');
                
                $root = $this->_tempDom->createElement('root');
                
                $this->_tempDom->appendChild($root);
                
                if ($this->_dom instanceof \DOMNodeList)
                {
                    foreach ($this->_dom as $domElement) {
                        $domNode = $this->_tempDom->importNode($domElement, true);
                        $root->appendChild($domNode);
                    }
                }
                else
                {
                    $domNode = $this->_tempDom->importNode($this->_dom, true);
                    $root->appendChild($domNode);
                }
            }
            
            return $this->_tempDom;
        }
    }
    
    protected function getXpath()
    {
        if ($this->_xpath === null) {
            $this->_xpath = new \DOMXpath($this->getDom());
        }
        
        return $this->_xpath;
    }
    
    public function getXpathSubquery($expression, $rel = false, $compile = true)
    {
        if ($compile) {
            $key = $expression . ($rel ? '>' : '*');

            if (isset(self::$_compiledXpath[$key])) {
                return self::$_compiledXpath[$key];
            }
        }
        
        $query = '';
        
        if (preg_match(self::regexp, $expression, $subs)) {
            $brackets = array();
            
            if (isset($subs['id']) && '' !== $subs['id']) {
                $brackets[] = "@id='". $subs['id'] ."'";
            }
            
            if (isset($subs['attr']) && '' !== $subs['attr']) {
                if (!(isset($subs['value'])))
                {
                    $brackets[] = "@". $subs['attr'];
                }
                else
                {
                    $attrValue = !empty($subs['value']) ? $subs['value'] : '';
                    $brackets[] = "@". $subs['attr'] ."='". $attrValue ."'";
                }
            }
            
            if (isset($subs['class']) && '' !== $subs['class']) {
                $brackets[] = 'contains(concat(" ", normalize-space(@class), " "), " '. $subs['class'] .' ")';
            }
            
            if (isset($subs['pseudo']) && '' !== $subs['pseudo']) {
                if ('first-child' === $subs['pseudo'])
                {
                    $brackets[] = '1';
                }
                else if ('last-child' === $subs['pseudo'])
                {
                    $brackets[] = 'last()';
                }
                else if ('nth-child' === $subs['pseudo'])
                {
                    if (isset($subs['expr']) && '' !== $subs['expr']) {
                        $e = $subs['expr'];
                        
                        if ('odd' === $e)
                        {
                            $brackets[] = '(position() -1) mod 2 = 0 and position() >= 1';
                        }
                        else if ('even' === $e)
                        {
                            $brackets[] = 'position() mod 2 = 0 and position() >= 0';
                        }
                        else if (preg_match("/^[0-9]+$/", $e))
                        {
                            $brackets[] = 'position() = '. $e;
                        }
                        else if (preg_match("/^((?P<mul>[0-9]+)n\+)(?P<pos>[0-9]+)$/is", $e, $esubs))
                        {
                            if (isset($esubs['mul']))
                            {
                                $brackets[] = '(position() -'. $esubs['pos'] .') mod '. $esubs['mul'] .' = 0 and position() >= '. $esubs['pos'] .'';
                            }
                            else
                            {
                                $brackets[] = ''. $e .'';
                            }
                        }
                    }
                }
            }
            
            $query = ($rel?'/':'//') . ((isset($subs['tag']) && '' !== $subs['tag']) ? $subs['tag'] : '*') . (($c = count($brackets)) ? ($c>1?'[('.implode(') and (', $brackets).')]':'['.implode(' and ', $brackets).']') : '');
            
            $left = trim(substr($expression, strlen($subs[0])));
            
            if ('' !== $left) {
                $query .= $this->getXpathSubquery($left, isset($subs['rel']) ? '>' === $subs['rel'] : false, $compile);
            }
        }
        
        if ($compile) {
            self::$_compiledXpath[$key] = $query;
        }
        
        return $query;
    }
    
    protected function getElements($xpathQuery, $returnNodeList = false)
    {
        if (strlen($xpathQuery)) {
            $nodeList = $this->getXpath()->query($xpathQuery);
            
            if ($nodeList === false) {
                throw new \Exception('Malformed xpath');
            }
            
            return $returnNodeList ? $nodeList : self::fromDom($nodeList);
        }
    }
    
    public function toDom($asIs = false)
    {
        return $this->getDom($asIs);
    }
    
    public function toXml()
    {
        return $this->getDom()->saveXML();
    }
    
    public function toHtml()
    {
        $result = '';
        
        if ($this->_dom instanceof \DOMDocument)
        {
            if ($this->_dom->hasChildNodes()) {
                foreach ($this->_dom->childNodes as $childNode) {
                    $result .= $this->_dom->saveHTML($childNode);
                }
            }
        }
        else if ($this->_dom instanceof \DOMNodeList || $this->_dom instanceof \DOMElement)
        {
            $nodes = $this->getDom()->getElementsByTagName('root')->item(0)->childNodes;

            foreach ($nodes as $childNode) {
                $result .= $childNode->ownerDocument->saveHTML($childNode);
            }
        }
        
        return $result;
    }
    
    public function toArray($xnode = null)
    {
        $array = array();
        
        if ($xnode === null)
        {
            if ($this->_dom instanceof \DOMNodeList) {
                foreach ($this->_dom as $node) {
                    $array[] = $this->toArray($node);
                }
                
                return $array;
            }
            
            $node = $this->getDom();
        }
        else
        {
            $node = $xnode;
        }
        
        if (in_array($node->nodeType, array(XML_TEXT_NODE, XML_COMMENT_NODE))) {
            return $node->nodeValue;
        }
        
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attr) {
                $array[$attr->nodeName] = $attr->nodeValue;
            }
        }
        
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $childNode) {
                $array[$childNode->nodeName][] = $this->toArray($childNode);
            }
        }
        
        if ($xnode === null) {
            $a = reset($array);
            return reset($a); // first child
        }
        
        return $array;
    }
    
    public function getNodes()
    {
        return $this->getDom(true);
    }
    
    /**
     * 
     * @return \ArrayIterator
     */
    public function items()
    {
        $a = array();
        
        if ($this->_dom instanceof \DOMNodeList)
        {
            foreach ($this->_dom as $domElement) {
                $a[] = self::fromDom($domElement);
            }
        }
        else if ($this->_dom instanceof \DOMElement)
        {
            $a[] = self::fromDom($this->_dom);
        }
        else
        {
            $node = $this->getDom();
            
            if ($node->hasChildnodes()) {
                foreach ($node->childNodes as $childNode) {
                    $a[] = self::fromDom($this->_dom);
                }
            }
        }
        
        return $a;
    }
    
    public function getIterator()
    {
        $a = $this->toArray();
        return new \ArrayIterator($a);
    }
    
    protected function _toTextArray($node = null, $skipChildren = false, $singleLevel = true)
    {
        $array = array();
        
        if ($node === null) {
            $node = $this->getDom();
        }
        
        if ($node instanceof \DOMNodeList) {
            foreach ($node as $child) {
                if ($singleLevel)
                {
                    $array = array_merge($array, $this->_toTextArray($child, $skipChildren, $singleLevel));
                }
                else
                {
                    $array[] = $this->_toTextArray($child, $skipChildren, $singleLevel);
                }
            }
            
            return $array;
        }
        
        if (XML_TEXT_NODE === $node->nodeType) {
            return array($node->nodeValue);
        }
        
        if (!$skipChildren) {
            if ($node->hasChildNodes()) {
                foreach ($node->childNodes as $childNode) {
                    if ($singleLevel)
                    {
                        $array = array_merge($array, $this->_toTextArray($childNode, $skipChildren, $singleLevel));
                    }
                    else
                    {
                        $array[] = $this->_toTextArray($childNode, $skipChildren, $singleLevel);
                    }
                }
            }
        }
        
        return $array;
    }
    
    /**
     * Возвращает массив содержащий текстовое содержимое каждого элемента дом дерева
     * @param boolean $skipChildren
     * @param boolean $singleLevel
     * @return array
     */
    public function toTextArray($skipChildren = false, $singleLevel = true)
    {
        return $this->_toTextArray($this->_dom, $skipChildren, $singleLevel);
    }
    
    /**
     * Возвращает текстовое содержимое дом дерева
     * @param string $glue
     * @param boolean $skipChildren
     * @return string
     */
    public function toText($glue = ' ', $skipChildren = false)
    {
        return implode($glue, $this->toTextArray($skipChildren, true));
    }
    
    /**
     * Аналогичен toText но не использует разделитель и удаляет с начала и конца строки лишние пробельные символы
     * @param boolean $skipChildren
     * @return string
     */
    public function text($skipChildren = false)
    {
        return trim($this->toText('', $skipChildren));
}
    
    /**
     * Возвращает значение атрибута элемента
     * @param string $name
     * @return mixed
     */
    public function attr($name, $firstChild = false)
    {
        if ($this->_dom instanceof \DOMNodeList)
        {
            foreach ($this->_dom as $domElement) {
                if ($domElement->hasAttribute($name)) {
                    return trim($domElement->getAttribute($name));
                }
                
                if ($firstChild === true) {
                    break;
                }
            }
        }
        else if ($this->_dom instanceof \DOMElement)
        {
            if ($this->_dom->hasAttribute($name)) {
                return trim($this->_dom->getAttribute($name));
            }
        }
        else
        {
            $node = $this->getDom();
            
            if ($node->hasChildNodes()) {
                foreach ($node->childNodes as $childNode) {
                    if ($childNode->hasAttribute($name)) {
                        return trim($childNode->getAttribute($name));
                    }
                    
                    if ($firstChild === true) {
                        break;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Удаляет из дерева найденные элементы
     */
    public function remove($expression, $xpath = false)
    {
        $nodeList = $this->getElements($this->getXpathSubquery($expression), true);
        
        foreach ($nodeList as $nodeElement) {
            $nodeElement->parentNode->removeChild($nodeElement);
        }
        
        return $this;
    }
}