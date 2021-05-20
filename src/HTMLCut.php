<?php
declare(strict_types=1);
namespace Simbiat;

class HTMLCut
{
    #Tags, that we consider irrelevant or harmful for preview
    public array $extraTags = [
        'applet', 'area', 'audio', 'base', 'blockquote', 'body', 'button', 'canvas', 'code', 'col', 'data', 'datalist', 'details', 'dialog', 'dir', 'embed', 'fieldset', 'figcapture', 'figure', 'font', 'footer', 'form', 'frame', 'frameset', 'header', 'html', 'iframe', 'img', 'input', 'ins', 'kbd', 'legend', 'link', 'main', 'map', 'meta', 'nav', 'noframes', 'noscript', 'object', 'optgroup', 'option', 'output', 'picture', 'pre', 'progress', 'q', 'rp', 'rt', 'ruby', 'samp', 'script', 'select', 'source', 'style', 'summary', 'svg', 'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'title', 'tr', 'track', 'tt', 'var', 'video',
    ];
    #Tags that we consider paragraphs
    public array $paraTags = [
        'article', 'aside', 'div', 'li', 'p', 'section',
    ];
    #Regex to remove punctuation symbols from the end of the string, that may make no sense there
    private string $punctuation = '/([:;,\[\(\-\{\<_„“‘«「﹁‹『﹃《〈]{1,}|\.{2,})$/ui';

    public function Cut(\DOMNode|string $string, int $length, int $paragraphs = 0, string $ellipsis = '…', bool $stripUnwanted = true): \DOMNode|string
    {
        #Sanitize length
        if ($length < 0) {
            $length = 0;
        }
        #Sanitize paragraphs
        if ($paragraphs < 0) {
            $paragraphs = 0;
        }
        if (is_string($string)) {
            #Check if string is too long without HTML tags
            if (mb_strlen(strip_tags($string), 'UTF-8') > $length) {
                #Convert to HTML DOM object
                $html = new \DOMDocument(encoding: 'UTF-8');
                #mb_convert_encoding is done as per workaround for UTF-8 loss/corruption on load from https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
                #LIBXML_HTML_NOIMPLIED and LIBXML_HTML_NOTED to avoid adding wrappers (html, body, DTD). This will also allow less issues in case string has both regular HTML and some regular text (outside of any tags). LIBXML_NOBLANKS to remove empty tags if any. LIBXML_PARSEHUGE to allow processing of larger strings. LIBXML_COMPACT for some potential optimization. LIBXML_NOWARNING and LIBXML_NOERROR to suppress warning in case of malformed HTML. LIBXML_NONET to protect from unsolicited connections to external sources.
                $html->loadHTML(mb_convert_encoding($string, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOBLANKS | LIBXML_PARSEHUGE | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
                $html->preserveWhiteSpace = false;
                $html->formatOutput = false;
                $html->normalizeDocument();
            }
        } else {
            #We already have a DOMNode
            $html = $string;
        }
        if (isset($html)) {
            #Set new length
            $newLength = 0;
            #Check of node has children
            if (count($html->childNodes) > 0) {
                #Iterrate children. While theoretically we can use the getElementsByTagName (as is also done further down the code), I was not able to get consistent results with it on this step, often not getting any text whatsoever.
                foreach ($html->childNodes as $key=>$node) {
                    #Get length of current node
                    $nodeLength = mb_strlen(strip_tags($node->nodeValue), 'UTF-8');
                    #Check if it fits
                    if ($newLength + $nodeLength <= $length) {
                        #Increase current length
                        $newLength += $nodeLength;
                        #Go to next node
                    } else {
                        #Need to cut the value
                        #Check if DOMText
                        if ($node instanceof \DOMText) {
                            #Cut directly in the DOM. Regex allows to retain whole words.
                            $html->childNodes->item($key)->nodeValue = preg_replace('/^(.{'.(($length - $newLength) > 0 ? '1,'.($length - $newLength) : '0,0').'}\b)(.*)/siu', '$1', $html->childNodes->item($key)->nodeValue);
                        } else {
                            #Recurse and replace current node with new (possibly cut) node
                            $html->replaceChild($this->Cut($node, $length - $newLength), $node);
                        }
                        #Get length of updated node
                        $nodeLength = mb_strlen(strip_tags($html->childNodes->item($key)->nodeValue), 'UTF-8');
                        if ($newLength + $nodeLength <= $length) {
                            #Update current length
                            $newLength += $nodeLength;
                        } else {
                            #Remove child, since its excessive
                            $node->parentNode->removeChild($node);
                        }
                    }
                }
            } else {
                #Check if what we have is already a \DOMText
                if ($html instanceof \DOMText) {
                    #Cut directly in the DOM. Regex allows to retain whole words.
                    $html->nodeValue = preg_replace('/^(.{'.(($length - $newLength) > 0 ? '1,'.($length - $newLength) : '0,0').'}\b)(.*)/siu', '$1', $html->nodeValue);
                }
            }
            if ($html instanceof \DOMDocument) {
                #Set xpath variable
                $xpath = new \DOMXPath($html);
                #Remove all tags, that do not make sense or have potential to harm in a preview
                if ($stripUnwanted) {
                    foreach ($xpath->query(implode('|', array_map(function($val) { return '//'.$val;} , $this->extraTags))) as $node) {
                        $node->parentNode->removeChild($node);
                    }
                }
                #Reduce number of paragraphs shown
                if ($paragraphs > 0) {
                    #Get current number of paragraphs. Also counting other elements, that generally look as separate paragraphs.
                    $curPar = $xpath->query(implode('|', array_map(function($val) { return '//'.$val;} , $this->paraTags)))->length;
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
                                if (in_array(strtolower($node->nodeName), $this->paraTags)) {
                                    $curPar--;
                                }
                                #Remove node
                                $node->parentNode->removeChild($node);
                            }
                        }
                    }
                }
                #Remove all empty nodes (taken from https://stackoverflow.com/questions/40367047/remove-all-empty-html-elements-using-php-domdocument). Using `while` allows for recursion
                while (($node_list = $xpath->query('//*[not(*) and not(@*) and not(text()[normalize-space()])]')) && $node_list->length) {
                    foreach ($node_list as $node) {
                        $node->parentNode->removeChild($node);
                    }
                }
                #Update string
                $newString = $html->saveHTML();
            } else {
                return $html;
            }
        }
        #Check if string got updated
        if (isset($newString)) {
            $string = $newString;
        }
        #Reduce number of paragraphs shown. While this has been done in terms of pure HTML, there is a chance, that we have regular text with regular newlines.
        if ($paragraphs > 0) {
            #Remove any whitespace between HTML tags, newlines before/after tags and also trim (as precaution)
            $string = trim(preg_replace('/([><])(\r+\n+|\r+|\n+)/iu', '$1', preg_replace('/(\r+\n+|\r+|\n+)([><])/iu', '$2', preg_replace('/>\s+</mu', '><', $string))));
            #Explode by newlines  (treat multiple newlines as one)
            $curPar = preg_split('/\r+\n+|\r+|\n+/u', $string);
            if (count($curPar) > $paragraphs) {
                #Slice and then implode back
                $newString = implode('<br>', array_slice($curPar, 0, $paragraphs));
            }
        }
        #Return
        if (isset($newString)) {
            #Remove some common punctuation from the end of the string (if any). These elements, when found ad the end of string, may look out of place. Also remove any excessive <br> at the beginning and end of the string.
            $string = preg_replace('/(^(<br>)+)|((<br>)+$)/iu', '', preg_replace($this->punctuation, '', $newString));
            #Return with ellipsis
            return nl2br($string.$ellipsis);
        } else {
            return nl2br($string);
        }
    }
}
