<?php
declare(strict_types = 1);

namespace Simbiat;

use function count, is_string, in_array;

/**
 * This is a class to cut HTML while preserving (to an extent) HTML structure.
 */
class HTMLCut
{
    /**
     * Tags, that we consider irrelevant or harmful for preview
     * @var array|string[]
     */
    public static array $extraTags = [
        'applet', 'area', 'audio', 'base', 'blockquote', 'button', 'canvas', 'code', 'col', 'data', 'datalist', 'details', 'dialog', 'dir', 'embed', 'fieldset', 'figcapture', 'figure', 'font', 'footer', 'form', 'frame', 'frameset', 'header', 'iframe', 'img', 'input', 'ins', 'kbd', 'legend', 'link', 'main', 'map', 'meta', 'nav', 'noframes', 'noscript', 'object', 'optgroup', 'option', 'output', 'picture', 'pre', 'progress', 'q', 'rp', 'rt', 'ruby', 'samp', 'script', 'select', 'source', 'style', 'summary', 'svg', 'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'title', 'tr', 'track', 'tt', 'var', 'video',
    ];
    /**
     * Tags that we consider paragraphs
     * @var array|string[]
     */
    public static array $paraTags = [
        'article', 'aside', 'div', 'li', 'p', 'section',
    ];
    /**
     * Regex to remove punctuation symbols from the end of the string, that may make no sense there
     * @var string
     */
    private static string $punctuation = '/([:;,\[(\-{<_„“‘«「﹁‹『﹃《〈]+|\.{2,})$/u';
    
    /**
     * Cut HTML to selected length
     * @param \DOMNode|string $string        String or DOMNode to process.
     * @param int             $length        Maximum length of the resulting string.
     * @param int             $paragraphs    Maximum number of paragraphs allowed. `0` means not limit.
     * @param string          $ellipsis      Symbol to use to indicate the string was cut. `…` (ellipsis, not 3 full-stops) is default one.
     * @param bool            $stripUnwanted Whether to remove tags, potentially harmful for preview (from `$extraTags` list).
     *
     * @return \DOMNode|string
     */
    public static function Cut(\DOMNode|string $string, int $length, int $paragraphs = 0, string $ellipsis = '…', bool $stripUnwanted = true): \DOMNode|string
    {
        #Sanitize length
        if ($length < 0) {
            $length = 0;
        }
        #Sanitize paragraphs
        if ($paragraphs < 0) {
            $paragraphs = 0;
        }
        $preserveP = false;
        $wrappedInHTML = false;
        if (is_string($string)) {
            #Remove HTML comments, CDATA and DOCTYPE
            $string = preg_replace('/\s*<!DOCTYPE[^>[]*(\[[^]]*])?>\s*/mui', '', preg_replace('/\s*<!\[CDATA\[.*?]]>\s*/muis', '', preg_replace('/\s*<!--.*?-->\s*/mus', '', $string)));
            if (preg_match('/^\s*<p>\s*/ui', $string) === 1) {
                $preserveP = true;
            }
            #Check if string is too long without HTML tags
            $initialLength = mb_strlen(strip_tags(html_entity_decode($string, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5)), 'UTF-8');
            #We need to wrap in HTML, due to behavior of LIBXML_HTML_NOIMPLIED and LibXML, but the string may be already wrapped.
            #If we do not do it, the HTML can get corrupted due to how the library processes the string.
            #More details on https://stackoverflow.com/questions/29493678/
            if (preg_match('/^\s*<html( [^<>]*)?>.*<\/html>\s*$/uis', $string) === 1) {
                $wrappedInHTML = true;
            } else {
                #Suppressing inspection, since we do not need the language for the purpose of the library
                /** @noinspection HtmlRequiredLangAttribute */
                $string = '<html>'.$string.'</html>';
            }
            if ($initialLength > $length) {
                #Convert to HTML DOM object
                $html = new \DOMDocument(encoding: 'UTF-8');
                #mb_convert_encoding is done as per workaround for UTF-8 loss/corruption on load from https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
                #LIBXML_HTML_NOIMPLIED and LIBXML_HTML_NOTED to avoid adding wrappers (html, body, DTD). This will also allow fewer issues in case string has both regular HTML and some regular text (outside any tags). LIBXML_NOBLANKS to remove empty tags if any. LIBXML_PARSEHUGE to allow processing of larger strings. LIBXML_COMPACT for some potential optimization. LIBXML_NOWARNING and LIBXML_NOERROR to suppress warning in case of malformed HTML. LIBXML_NONET to protect from unsolicited connections to external sources.
                $html->loadHTML(mb_convert_encoding($string, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOBLANKS | LIBXML_PARSEHUGE | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
                $html->preserveWhiteSpace = false;
                $html->formatOutput = false;
                $html->normalizeDocument();
            }
        } else {
            #We already have a DOMNode
            $initialLength = mb_strlen(strip_tags(html_entity_decode($string->nodeValue ?? $string->textContent, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5)), 'UTF-8');
            $html = $string;
        }
        if (isset($html)) {
            #Set new length
            $newLength = 0;
            #This if a flag to indicate that we determined, that we've cut enough already
            $finalCut = false;
            #Check of node has children
            $nodesCount = count($html->childNodes);
            if ($nodesCount > 0) {
                #Prepare array for list of nodes, that we are keeping
                $nodesToKeep = [];
                #Iterrate children. While theoretically we can use the getElementsByTagName (as is also done further down the code), I was not able to get consistent results with it on this step, often not getting any text whatsoever.
                foreach ($html->childNodes as $key => $node) {
                    #Skip HTML comments, CDATA and DOCTYPE
                    if ($node instanceof \DOMComment || $node instanceof \DOMCdataSection || $node instanceof \DOMNotation) {
                        continue;
                    }
                    #Skip node, if we determined that final cut was done on a previous iteration
                    if ($finalCut) {
                        continue;
                    }
                    #Get length of current node
                    $nodeLengthBeforeCut = mb_strlen(strip_tags(html_entity_decode($node->nodeValue ?? $node->textContent, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5)), 'UTF-8');
                    #Check if it fits
                    if ($newLength + $nodeLengthBeforeCut <= $length) {
                        #Increase current length
                        $newLength += $nodeLengthBeforeCut;
                        #Add to list of nodes to preserve and go to next node
                        $nodesToKeep[] = $key;
                    } else {
                        #Need to cut the value
                        #Check if DOMText
                        if ($node instanceof \DOMText) {
                            #Cut directly in the DOM. Regex allows to retain whole words.
                            $html->childNodes->item($key)->nodeValue = preg_replace('/^(((&(?:[a-z\d]+|#\d+|#x[a-f\d]+);)|.){'.(($length - $newLength) > 0 ? '1,'.($length - $newLength) : '0,0').'}\b)(.*)/siu', '$1', $html->childNodes->item($key)->nodeValue);
                        } else {
                            #Recurse and replace current node with new (possibly cut) node
                            $newNode = self::Cut($node, $length - $newLength);
                            if (!empty($newNode->nodeValue)) {
                                $html->replaceChild($newNode, $node);
                            }
                        }
                        #Get length of updated node
                        $nodeLengthAfterCut = mb_strlen(strip_tags(html_entity_decode($html->childNodes->item($key)->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5)), 'UTF-8');
                        if ($newLength + $nodeLengthAfterCut <= $length) {
                            #Update current length
                            $newLength += $nodeLengthAfterCut;
                            #If original content, that we were cutting was long enough for the length, we required, and we did cut it, it means, that there is no point to cut further, otherwise content may be taken out of the middle of text
                            if ($nodeLengthBeforeCut >= $length) {
                                $finalCut = true;
                            }
                            #Add to list of nodes to preserve and go to next node
                            $nodesToKeep[] = $key;
                        }
                    }
                }
                #Remove all excessive nodes from $html. Need to do it separately, sine removal works only if we iterate in reverse
                #We can safely do this at this point, because we have updated the nodes' values appropriately already, so if something was cut - it's already there in the DOM
                for ($key = $nodesCount; --$key >= 0;) {
                    if (!in_array($key, $nodesToKeep, true)) {
                        $node = $html->childNodes->item($key);
                        $node?->parentNode->removeChild($node);
                    }
                }
            } elseif ($html instanceof \DOMText) {
                #Cut directly in the DOM. Regex allows to retain whole words. We also trim the text inside the nodes
                $html->nodeValue = preg_replace('/>\s+/u', '>', preg_replace('/\s+</u', '<', preg_replace('/^(((&(?:[a-z\d]+|#\d+|#x[a-f\d]+);)|.){'.(($length - $newLength) > 0 ? '1,'.($length - $newLength) : '0,0').'}\b)(.*)/siu', '$1', $html->nodeValue)));
            }
            if ($html instanceof \DOMDocument) {
                #Set xpath variable
                $xpath = new \DOMXPath($html);
                #Remove all tags, that do not make sense or have potential to harm in a preview
                if ($stripUnwanted) {
                    $unwantedTags = $xpath->query(implode('|', array_map(static function ($val) {
                        return '//'.$val;
                    }, self::$extraTags)));
                    $unwantedCount = count($unwantedTags);
                    for ($key = $unwantedCount; --$key >= 0;) {
                        $node = $unwantedTags[$key];
                        $node->parentNode->removeChild($node);
                    }
                }
                #Reduce number of paragraphs shown
                if ($paragraphs > 0) {
                    #Get current number of paragraphs. Also counting other elements, that generally look as separate paragraphs.
                    $curPar = $xpath->query(implode('|', array_map(static function ($val) {
                        return '//'.$val;
                    }, self::$paraTags)))->length;
                    #Check if number of current paragraphs is larger than allowed. Do not do processing, if it's not.
                    if ($curPar > $paragraphs) {
                        #Get all tags
                        $tags = $html->getElementsByTagName('*');
                        #Iterrate backwards (as per https://www.php.net/manual/en/class.domnodelist.php#83390). Regular iteration seems to provide strange results.
                        for ($i = $tags->length; --$i >= 0;) {
                            #Check if number of current paragraphs is larger than allowed
                            if ($curPar > $paragraphs) {
                                #Get actual node
                                $node = $tags->item($i);
                                if (in_array(mb_strtolower($node->nodeName, 'UTF-8'), self::$paraTags, true)) {
                                    $curPar--;
                                }
                                #Remove node
                                $node->parentNode->removeChild($node);
                            }
                        }
                    }
                }
                #Remove all empty nodes (taken from https://stackoverflow.com/questions/40367047/remove-all-empty-html-elements-using-php-domdocument). Using `while` allows for recursion
                while (($node_list = $xpath->query('//*[not(*) and not(@*) and not(text()[string-length(normalize-space()) > 0])]')) && $node_list->length) {
                    $emptyCount = count($node_list);
                    for ($key = $emptyCount; --$key >= 0;) {
                        $node = $node_list[$key];
                        $node->parentNode->removeChild($node);
                    }
                }
                #Update string by saving object as HTML string, but strip some standard tags added by PHP
                $newString = preg_replace('/>\s+/u', '>', preg_replace('/\s+</u', '<', preg_replace('/(<!DOCTYPE html PUBLIC "-\/\/W3C\/\/DTD HTML 4\.0 Transitional\/\/EN" "http:\/\/www\.w3\.org\/TR\/REC-html40\/loose\.dtd">\s*<html>\s*<body>\s*)(.*)(<\/body><\/html>)/uis', '$2', $html->saveHTML())));
            } else {
                return $html;
            }
        }
        #Check if string got updated
        if (isset($newString) && is_string($newString)) {
            $string = $newString;
            $newString = null;
        }
        #Strip the excessive HTML tags, if we added them
        if ($wrappedInHTML === false) {
            $string = preg_replace('/(^\s*<html( [^<>]*)?>)(.*)(<\/html>\s*$)/uis', '$3', $string);
        }
        #Reduce number of paragraphs shown. While this has been done in terms of pure HTML, there is a chance, that we have regular text with regular newlines.
        if ($paragraphs > 0) {
            #Remove any whitespace between HTML tags, newlines before/after tags and also trim (as precaution)
            $string = trim(preg_replace('/([><])(\R+)/u', '$1', preg_replace('/(\R+)([><])/u', '$2', preg_replace('/>\s+</mu', '><', $string))));
            #Explode by newlines  (treat multiple newlines as one)
            $curPar = preg_split('/\R+/u', $string);
            if (count($curPar) > $paragraphs) {
                #Slice and then implode back
                $string = implode("\r\n", \array_slice($curPar, 0, $paragraphs));
            }
        }
        #Remove some common punctuation from the end of the string (if any). These elements, when found ad the end of string, may look out of place. Also remove any excessive <br> at the beginning and end of the string.
        $string = preg_replace('/(^(<br>)+)|((<br>)+$)/iu', '', preg_replace(self::$punctuation, '', $string));
        #If we did not have a <p> tag at the beginning of the string and now new string has it - remove it, since it was added by conversion to HTML
        if (!$preserveP && preg_match('/^\s*<p>\s*/ui', $string) === 1) {
            $string = preg_replace('/^\s*<p>\s*/ui', '', $string);
            #Also remove closing tag from the end
            $string = preg_replace('/\s*<\/p>\s*$/ui', '', $string);
        }
        #Get current length
        $currentLength = mb_strlen(strip_tags(html_entity_decode($string, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5)), 'UTF-8');
        $string = trim($string);
        #Return with optional ellipsis
        if ($initialLength > $currentLength) {
            #Check if we have any closing tags at the end (most likely we do)
            $closingTagsString = preg_replace('/^(.*[^><\/\s]+)((\s*<\s*\/\s*[a-z-A-Z\d\-]+\s*>\s*)+)$/uis', '$2', $string);
            #If no closing tags found - add ellipsis to the end of string
            if (preg_match('/^\s*$/u', $closingTagsString) === 1) {
                return $string.$ellipsis;
            }
            #Get the tags
            $closingTags = preg_split('/(\s*<\s*\/\s*)|(\s*>\s*)|(\s*>\s*<\s*\/\s*)/', $closingTagsString, -1, PREG_SPLIT_NO_EMPTY);
            $closingTags = array_reverse($closingTags, true);
            #Iterrate from the end of the array to find the last tag, that can semantically have text
            $lastTag = '';
            foreach ($closingTags as $tag) {
                if (in_array(mb_strtolower($tag, 'UTF-8'), [
                    #Content sectioning tags, which still can have text directly inside
                    'address', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'article', 'section', 'aside',
                    #Text blocks, that can have text directly inside of them. UL and OL, for example can have it only in child `li` elements, thus they do not fit.
                    'blockquote', 'dd', 'div', 'dl', 'dt', 'figcaption', 'li', 'p', 'pre',
                    #Inline elements
                    'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var',
                    #Other tags, that may have text directly in them
                    'noscript', 'del', 'ins', 'td', 'th', 'caption', 'details', 'dialog',
                ])) {
                    #Tag found - stop loop
                    $lastTag = $tag;
                    break;
                }
            }
            #If suitable tag was not found, add ellipsis to the end of string
            if (empty($lastTag)) {
                return $string.$ellipsis;
            }
            #If found - add ellipsis before the closing tag. `strrev` is used in order to replace the last occurrence of the closing tag exactly.
            $closingTagsNew = strrev(preg_replace('/(\s*>\s*'.strrev($lastTag).'\/\s*<)/uis', '$1'.strrev($ellipsis), strrev($closingTagsString), 1));
            #Replace tags in the string itself
            return substr_replace($string, $closingTagsNew, mb_strrpos($string, $closingTagsString, 0, 'UTF-8'), mb_strlen($closingTagsString, 'UTF-8'));
        }
        return $string;
    }
}
