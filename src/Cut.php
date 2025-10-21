<?php
declare(strict_types = 1);

namespace Simbiat\HTML;

use function count, is_string, in_array;

/**
 * This is a class to cut HTML while preserving (to an extent) HTML structure.
 */
class Cut
{
    /**
     * Tags that we consider irrelevant or harmful for preview
     * @var array|string[]
     */
    public static array $extra_tags = [
        'applet', 'area', 'audio', 'base', 'blockquote', 'button', 'canvas', 'code', 'col', 'data', 'datalist', 'details', 'dialog', 'dir', 'embed', 'fieldset', 'figcapture', 'figure', 'font', 'footer', 'form', 'frame', 'frameset', 'header', 'iframe', 'img', 'input', 'ins', 'kbd', 'legend', 'link', 'main', 'map', 'meta', 'nav', 'noframes', 'noscript', 'object', 'optgroup', 'option', 'output', 'picture', 'pre', 'progress', 'q', 'rp', 'rt', 'ruby', 'samp', 'script', 'select', 'source', 'style', 'summary', 'svg', 'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'title', 'tr', 'track', 'tt', 'var', 'video',
    ];
    /**
     * Tags that we consider paragraphs
     * @var array|string[]
     */
    public static array $paragraph_tags = [
        'article', 'aside', 'div', 'li', 'p', 'section',
    ];
    /**
     * Regex to remove punctuation symbols from the end of the string, that may make no sense there
     * @var string
     */
    public const string PUNCTUATION = '/([:;,\[(\-{<_„“‘«「﹁‹『﹃《〈]+|\.{2,})$/u';
    
    /**
     * Cut HTML to the selected length
     *
     * @param \DOMNode|string $string         String or DOMNode to process.
     * @param int             $length         Maximum length of the resulting string.
     * @param int             $paragraphs     Maximum number of paragraphs allowed. `0` means no limit.
     * @param string          $ellipsis       Symbol to use to indicate the string was cut. `…` (ellipsis, not 3 full-stops) is the default one.
     * @param bool            $strip_unwanted Whether to remove tags, potentially harmful for preview (from the `$extra_tags` list).
     *
     * @return \DOMNode|string
     */
    public static function cut(\DOMNode|string $string, int $length, int $paragraphs = 0, string $ellipsis = '…', bool $strip_unwanted = true): \DOMNode|string
    {
        #Sanitize length
        if ($length < 0) {
            $length = 0;
        }
        #Sanitize paragraphs
        if ($paragraphs < 0) {
            $paragraphs = 0;
        }
        $preserve_paragraph = false;
        $wrapped_in_html = false;
        if (is_string($string)) {
            #Remove HTML comments, CDATA and DOCTYPE
            $string = \preg_replace('/\s*<!DOCTYPE[^>[]*(\[[^]]*])?>\s*/mui', '', \preg_replace('/\s*<!\[CDATA\[.*?]]>\s*/muis', '', \preg_replace('/\s*<!--.*?-->\s*/mus', '', $string)));
            if (\preg_match('/^\s*<p>\s*/ui', $string) === 1) {
                $preserve_paragraph = true;
            }
            #Check if string is too long without HTML tags
            $initial_length = mb_strlen(\strip_tags(\html_entity_decode($string, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5)), 'UTF-8');
            #We need to wrap in HTML, due to the behavior of LIBXML_HTML_NOIMPLIED and LibXML, but the string may be already wrapped.
            #If we do not do it, the HTML can get corrupted due to how the library processes the string.
            #More details on https://stackoverflow.com/questions/29493678/
            if (\preg_match('/^\s*<html( [^<>]*)?>.*<\/html>\s*$/uis', $string) === 1) {
                $wrapped_in_html = true;
            } else {
                #Suppressing inspection, since we don't need the language for the library
                /** @noinspection HtmlRequiredLangAttribute */
                $string = '<html>'.$string.'</html>';
            }
            if ($initial_length > $length) {
                #Convert to the HTML DOM object
                $html = new \DOMDocument(encoding: 'UTF-8');
                #`mb_convert_encoding` is done as per workaround for UTF-8 loss/corruption on loading from https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
                #LIBXML_HTML_NOIMPLIED and LIBXML_HTML_NOTED to avoid adding wrappers (html, body, DTD). This will also allow fewer issues in case string has both regular HTML and some regular text (outside any tags). LIBXML_NOBLANKS to remove empty tags if any. LIBXML_PARSEHUGE to allow processing of larger strings. LIBXML_COMPACT for some potential optimization. LIBXML_NOWARNING and LIBXML_NOERROR to suppress warning in case of malformed HTML. LIBXML_NONET to protect from unsolicited connections to external sources.
                $html->loadHTML(mb_convert_encoding($string, 'HTML-ENTITIES', 'UTF-8'), \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NOBLANKS | \LIBXML_PARSEHUGE | \LIBXML_COMPACT | \LIBXML_NOWARNING | \LIBXML_NOERROR | \LIBXML_NONET);
                $html->preserveWhiteSpace = false;
                $html->formatOutput = false;
                $html->normalizeDocument();
            }
        } else {
            #We already have a DOMNode
            $initial_length = mb_strlen(\strip_tags(\html_entity_decode($string->nodeValue ?? $string->textContent, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5)), 'UTF-8');
            $html = $string;
        }
        if (isset($html)) {
            #Set new length
            $new_length = 0;
            #This if a flag to indicate that we determined that we've cut enough already
            $final_cut = false;
            #Check of node has children
            $nodes_count = count($html->childNodes);
            if ($nodes_count > 0) {
                #Prepare an array for list of nodes, that we are keeping
                $nodes_to_keep = [];
                #Iterrate children. While theoretically we can use the getElementsByTagName (as is also done further down the code), I was not able to get consistent results with it on this step, often not getting any text whatsoever.
                foreach ($html->childNodes as $key => $node) {
                    #Skip HTML comments, CDATA, and DOCTYPE
                    if ($node instanceof \DOMComment || $node instanceof \DOMCdataSection || $node instanceof \DOMNotation) {
                        continue;
                    }
                    #Skip node, if we determined that final cut was done on a previous iteration
                    if ($final_cut) {
                        continue;
                    }
                    #Get length of the current node
                    $node_length_before_cut = mb_strlen(\strip_tags(\html_entity_decode($node->nodeValue ?? $node->textContent, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5)), 'UTF-8');
                    #Check if it fits
                    if ($new_length + $node_length_before_cut <= $length) {
                        #Increase current length
                        $new_length += $node_length_before_cut;
                        #Add to the list of nodes to preserve and go to the next node
                        $nodes_to_keep[] = $key;
                    } else {
                        #Need to cut the value
                        #Check if DOMText
                        if ($node instanceof \DOMText) {
                            #Cut directly in the DOM. Regex allows retaining whole words.
                            $html->childNodes->item($key)->nodeValue = \preg_replace('/^(((&(?:[a-z\d]+|#\d+|#x[a-f\d]+);)|.){'.(($length - $new_length) > 0 ? '1,'.($length - $new_length) : '0,0').'}\b)(.*)/siu', '$1', $html->childNodes->item($key)->nodeValue);
                        } else {
                            #Recurse and replace the current node with new (possibly cut) node
                            $new_node = self::cut($node, $length - $new_length);
                            if (!empty($new_node->nodeValue)) {
                                $html->replaceChild($new_node, $node);
                            }
                        }
                        #Get length of updated node
                        $node_length_after_cut = mb_strlen(\strip_tags(\html_entity_decode($html->childNodes->item($key)->nodeValue, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5)), 'UTF-8');
                        if ($new_length + $node_length_after_cut <= $length) {
                            #Update current length
                            $new_length += $node_length_after_cut;
                            #If the original content that we were cutting was long enough for the length we required, and we did cut it, it means, that there is no point to cut further. Otherwise, content may be taken out of the middle of text.
                            if ($node_length_before_cut >= $length) {
                                $final_cut = true;
                            }
                            #Add to the list of nodes to preserve and go to the next node
                            $nodes_to_keep[] = $key;
                        }
                    }
                }
                #Remove all excessive nodes from $html. Need to do it separately, sine removal works only if we iterate in reverse
                #We can safely do this at this point, because we've updated the nodes' values appropriately already, so if something was cut - it is already there in the DOM
                for ($key = $nodes_count; --$key >= 0;) {
                    if (!in_array($key, $nodes_to_keep, true)) {
                        $node = $html->childNodes->item($key);
                        $node?->parentNode->removeChild($node);
                    }
                }
            } elseif ($html instanceof \DOMText) {
                #Cut directly in the DOM. Regex allows to retain whole words. We also trim the text inside the nodes
                $html->nodeValue = \preg_replace('/>\s+/u', '>', \preg_replace('/\s+</u', '<', \preg_replace('/^(((&(?:[a-z\d]+|#\d+|#x[a-f\d]+);)|.){'.(($length - $new_length) > 0 ? '1,'.($length - $new_length) : '0,0').'}\b)(.*)/siu', '$1', $html->nodeValue)));
            }
            if ($html instanceof \DOMDocument) {
                #Set xpath variable
                $xpath = new \DOMXPath($html);
                #Remove all tags, that do not make sense or have potential to harm in a preview
                if ($strip_unwanted) {
                    $unwanted_tags = $xpath->query(\implode('|', \array_map(static function ($val) {
                        return '//'.$val;
                    }, self::$extra_tags)));
                    $unwanted_count = count($unwanted_tags);
                    for ($key = $unwanted_count; --$key >= 0;) {
                        $node = $unwanted_tags[$key];
                        $node->parentNode->removeChild($node);
                    }
                }
                #Reduce the number of paragraphs shown
                if ($paragraphs > 0) {
                    #Get the current number of paragraphs. Also counting other elements, that generally look as separate paragraphs.
                    $current_paragraphs = $xpath->query(\implode('|', \array_map(static function ($val) {
                        return '//'.$val;
                    }, self::$paragraph_tags)))->length;
                    #Check if the number of current paragraphs is larger than allowed. Do not do processing, if it's not.
                    if ($current_paragraphs > $paragraphs) {
                        #Get all tags
                        $tags = $html->getElementsByTagName('*');
                        #Iterrate backwards (as per https://www.php.net/manual/en/class.domnodelist.php#83390). Regular iteration seems to provide strange results.
                        for ($iterator = $tags->length; --$iterator >= 0;) {
                            #Check if the number of current paragraphs is larger than allowed
                            if ($current_paragraphs > $paragraphs) {
                                #Get actual node
                                $node = $tags->item($iterator);
                                if (in_array(mb_strtolower($node->nodeName, 'UTF-8'), self::$paragraph_tags, true)) {
                                    $current_paragraphs--;
                                }
                                #Remove node
                                $node->parentNode->removeChild($node);
                            }
                        }
                    }
                }
                #Remove all empty nodes (taken from https://stackoverflow.com/questions/40367047/remove-all-empty-html-elements-using-php-domdocument). Using `while` allows for recursion
                while (($node_list = $xpath->query('//*[not(*) and not(@*) and not(text()[string-length(normalize-space()) > 0])]')) && $node_list->length) {
                    $empty_count = count($node_list);
                    for ($key = $empty_count; --$key >= 0;) {
                        $node = $node_list[$key];
                        $node->parentNode->removeChild($node);
                    }
                }
                #Update string by saving object as HTML string, but strip some standard tags added by PHP
                $new_string = \preg_replace('/>\s+/u', '>', \preg_replace('/\s+</u', '<', \preg_replace('/(<!DOCTYPE html PUBLIC "-\/\/W3C\/\/DTD HTML 4\.0 Transitional\/\/EN" "http:\/\/www\.w3\.org\/TR\/REC-html40\/loose\.dtd">\s*<html>\s*<body>\s*)(.*)(<\/body><\/html>)/uis', '$2', $html->saveHTML())));
            } else {
                return $html;
            }
        }
        #Check if string got updated
        if (isset($new_string) && is_string($new_string)) {
            $string = $new_string;
            $new_string = null;
        }
        #Strip the excessive HTML tags if we added them
        if (!$wrapped_in_html) {
            $string = \preg_replace('/(^\s*<html( [^<>]*)?>)(.*)(<\/html>\s*$)/uis', '$3', $string);
        }
        #Reduce the number of paragraphs shown. While this has been done in terms of pure HTML, there is a chance, that we have regular text with regular newlines.
        if ($paragraphs > 0) {
            #Remove any whitespace between HTML tags, newlines before/after tags and also trim (as precaution)
            $string = mb_trim(\preg_replace('/([><])(\R+)/u', '$1', \preg_replace('/(\R+)([><])/u', '$2', \preg_replace('/>\s+</mu', '><', $string))), null, 'UTF-8');
            #Explode by newlines (treat multiple newlines as one)
            $current_paragraphs = \preg_split('/\R+/u', $string);
            if (count($current_paragraphs) > $paragraphs) {
                #Slice and then implode back
                $string = \implode("\r\n", \array_slice($current_paragraphs, 0, $paragraphs));
            }
        }
        #Remove some common punctuation from the end of the string (if any). These elements, when found ad the end of string, may look out of place. Also remove any excessive <br> at the beginning and end of the string.
        $string = \preg_replace('/(^(<br>)+)|((<br>)+$)/iu', '', \preg_replace(self::PUNCTUATION, '', $string));
        #If we did not have a <p> tag at the beginning of the string and now new string has it - remove it, since it was added by conversion to HTML
        if (!$preserve_paragraph && \preg_match('/^\s*<p>\s*/ui', $string) === 1) {
            $string = \preg_replace('/^\s*<p>\s*/ui', '', $string);
            #Also remove closing tag from the end
            $string = \preg_replace('/\s*<\/p>\s*$/ui', '', $string);
        }
        #Get current length
        $current_length = mb_strlen(\strip_tags(\html_entity_decode($string, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5)), 'UTF-8');
        $string = mb_trim($string, null, 'UTF-8');
        #Return with optional ellipsis
        if ($initial_length > $current_length) {
            #Check if we have any closing tags at the end (most likely we do)
            $closing_tags_string = \preg_replace('/^(.*[^><\/\s]+)((\s*<\s*\/\s*[a-z-A-Z\d\-]+\s*>\s*)+)$/uis', '$2', $string);
            #If no closing tags found - add ellipsis to the end of string
            if (\preg_match('/^\s*$/u', $closing_tags_string) === 1) {
                return $string.$ellipsis;
            }
            #Get the tags
            $closing_tags = \preg_split('/(\s*<\s*\/\s*)|(\s*>\s*)|(\s*>\s*<\s*\/\s*)/', $closing_tags_string, -1, \PREG_SPLIT_NO_EMPTY);
            $closing_tags = \array_reverse($closing_tags, true);
            #Iterrate from the end of the array to find the last tag, that can semantically have some text
            $last_tag = '';
            foreach ($closing_tags as $tag) {
                if (in_array(mb_strtolower($tag, 'UTF-8'), [
                    #Content sectioning tags, which still can have some text directly inside
                    'address', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'article', 'section', 'aside',
                    #Text blocks, that can have some text directly inside them. UL and OL, for example, can have it only in child `li` elements thus they do not fit.
                    'blockquote', 'dd', 'div', 'dl', 'dt', 'figcaption', 'li', 'p', 'pre',
                    #Inline elements
                    'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'dfn', 'em', 'iterator', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var',
                    #Other tags, that may have some text directly in them
                    'noscript', 'del', 'ins', 'td', 'th', 'caption', 'details', 'dialog',
                ])) {
                    #Tag found - stop loop
                    $last_tag = $tag;
                    break;
                }
            }
            #If a suitable tag was not found, add ellipsis to the end of string
            if (empty($last_tag)) {
                return $string.$ellipsis;
            }
            #If found - add ellipsis before the closing tag. `strrev` is used to replace the last occurrence of the closing tag exactly.
            $closing_tags_new = \strrev(\preg_replace('/(\s*>\s*'.\strrev($last_tag).'\/\s*<)/uis', '$1'.\strrev($ellipsis), \strrev($closing_tags_string), 1));
            #Replace tags in the string itself
            return \substr_replace($string, $closing_tags_new, mb_strrpos($string, $closing_tags_string, 0, 'UTF-8'), mb_strlen($closing_tags_string, 'UTF-8'));
        }
        return $string;
    }
}
