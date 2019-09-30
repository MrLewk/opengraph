<?php
    /*
      Copyright 2010 Scott MacVicar

       Licensed under the Apache License, Version 2.0 (the "License");
       you may not use this file except in compliance with the License.
       You may obtain a copy of the License at

           http://www.apache.org/licenses/LICENSE-2.0

       Unless required by applicable law or agreed to in writing, software
       distributed under the License is distributed on an "AS IS" BASIS,
       WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
       See the License for the specific language governing permissions and
       limitations under the License.

        Original can be found at https://github.com/scottmac/opengraph/blob/master/OpenGraph.php

    */

    /*
     * Modified by Luke Wilson, September 2019
     * Updates: Added user agent detection to get the best results from different websites via cURL
     *          Added more support to detect twitter meta tags to pull more info or video details etc
     *          Added more fallbacks for less structured meta tag code
     *          Added JSON+LD detection support
     *          Added relative image URL support to convert to absolute URL
     *          Improved cURL lookup and error reporting
     */

    class OpenGraph implements
        Iterator {
        /**
         * There are base schema's based on type, this is just
         * a map so that the schema can be obtained
         *
         */
        public static $TYPES = array(
            'activity'     => array(
                'activity',
                'sport'
            ),
            'business'     => array(
                'bar',
                'company',
                'cafe',
                'hotel',
                'restaurant'
            ),
            'group'        => array(
                'cause',
                'sports_league',
                'sports_team'
            ),
            'organization' => array(
                'band',
                'government',
                'non_profit',
                'school',
                'university'
            ),
            'person'       => array(
                'actor',
                'athlete',
                'author',
                'director',
                'musician',
                'politician',
                'public_figure'
            ),
            'place'        => array(
                'city',
                'country',
                'landmark',
                'state_province'
            ),
            'product'      => array(
                'album',
                'book',
                'drink',
                'food',
                'game',
                'movie',
                'product',
                'song',
                'tv_show'
            ),
            'website'      => array(
                'blog',
                'website'
            ),
        );

        /**
         * Holds all the Open Graph values we've parsed from a page
         *
         */
        private $_values = array();
        /**
         * Iterator code
         */
        private $_position = 0;

        /**
         * Fetches a URI and parses it for Open Graph data, returns
         * false on error.
         *
         * @param $URI    URI to page to parse for Open Graph data
         *
         * @return OpenGraph
         */
        static public function fetch($URI) {
            $cookie_path = 'cookie.txt';
            if(defined('COOKIE_PATH_FOR_CURL') && !empty(COOKIE_PATH_FOR_CURL)) {
                $cookie_path = COOKIE_PATH_FOR_CURL;
            }

            $UAarry = array(
                "facebook"  => "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)",
                "google"    => "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
                "googleImg" => "Googlebot-Image/1.0",
                "moz"       => "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:31.0) Gecko/20100101 Firefox/31.0 ",
                "user"      => $_SERVER['HTTP_USER_AGENT']
            );

            //Added by Luke Wilson, Sept 2019 -- certain website react differently depending on which robot UA it detects, so not all the meta tags get returned in some cases
            if(preg_match('/(facebook|wish)/', strtolower($URI))) {
                $userAgent = $UAarry['user'];
            } elseif(preg_match('/(amazon|youtu|missguided)/', $URI)) {
                $userAgent = $UAarry['facebook'];
            } else {
                $userAgent = $UAarry['google']; //generally the Googlebot gets all the info
            }

            //cURL function modified based on https://stackoverflow.com/q/42395874/1235692
            $curl = curl_init($URI);

            $header[0] = "Accept: text/html, text/xml,application/xml,application/xhtml+xml,";
            $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
            $header[] = "Cache-Control: max-age=0";
            $header[] = "Connection: keep-alive";
            $header[] = "Keep-Alive: 300";
            $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
            $header[] = "Accept-Language: en-us,en;q=0.5";
            $header[] = "Pragma: no-cache";
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curl, CURLOPT_URL, $URI);
            //Get return headers
            curl_setopt($curl, CURLOPT_HEADER, 0);
            curl_setopt($curl, CURLOPT_NOBODY, 0);

            curl_setopt($curl, CURLOPT_FAILONERROR, TRUE);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, TRUE);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, TRUE);
            curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);
            //The following 2 set up lines work with sites like www.nytimes.com
            curl_setopt($curl, CURLOPT_COOKIESESSION, TRUE);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $cookie_path); //you can change this path to whetever you want.
            curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie_path); //you can change this path to whetever you want.

            $response = mb_convert_encoding(curl_exec($curl), 'HTML-ENTITIES', 'UTF-8');

            if(!empty($response)) {
                return self::_parse($response);
            } else {
                $header_data = curl_getinfo($curl);
                //print_r($header_data);
                //header('Header error: '.$header_data['http_code'], TRUE, $header_data['http_code']);

                //return '<h1 class="redColour">Curl error: ' . curl_error($curl) . "</h1>";

                return array(
                    "status"     => "error",
                    "headerCode" => $header_data['http_code'],
                    "message"    => "URL Fetch Error: " . curl_error($curl),
                    "url"        => $header_data['url'],
                );

                //return FALSE;
            }
            curl_close($curl);
        }

        /**
         * Parses HTML and extracts Open Graph data, this assumes
         * the document is at least well formed.
         *
         * @param $HTML    HTML to parse
         *
         * @return OpenGraph
         */
        static private function _parse($HTML) {
            $old_libxml_error = libxml_use_internal_errors(TRUE);


            $doc = new DOMDocument();
            $doc->loadHTML($HTML);
            $nonOgDescription = NULL;
            $nonOgURL = NULL;
            $nonOgImg = NULL;
            $nonOgRating = NULL;


            //Added by Luke Wilson, Sept 2019
            //Based off: https://stackoverflow.com/a/35975317/1235692
            $xpath = new DOMXpath($doc);
            $jsonScripts = $xpath->query('//script[@type="application/ld+json"]');
            if($jsonScripts->length > 0) {
                //$json = trim( $jsonScripts->item(0)->nodeValue );
                //$data = json_decode( $json, true );
                $json = array();
                $jsonError = array();
                $i = 0;
                foreach($jsonScripts as $node) {
                    //$json[] = json_decode( $node->nodeValue, true );
                    // $json[] = json_decode(trim(preg_replace('/\s+/', '',$node->nodeValue)), true);
                    $jsonFormat = trim(preg_replace('/\s+/', '', $node->nodeValue));

                    $json[] = json_decode($jsonFormat, TRUE);
                    //$json[] = $jsonFormat;

                    switch(json_last_error()) {
                        case JSON_ERROR_DEPTH:
                            $jsonError[] = 'Maximum stack depth exceeded';
                            break;
                        case JSON_ERROR_STATE_MISMATCH:
                            $jsonError[] = 'Underflow or the modes mismatch';
                            break;
                        case JSON_ERROR_CTRL_CHAR:
                            $jsonError[] = 'Unexpected control character found';
                            break;
                        case JSON_ERROR_SYNTAX:
                            $jsonError[] = 'Syntax error, malformed JSON';
                            break;
                        case JSON_ERROR_UTF8:
                            $jsonError[] = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                            break;
                    }

                    if(empty($jsonError)) {
                        if(array_key_exists("image", $json[$i])) {
                            $nonOgImg = $json[$i]['image'];
                            break;
                        }
                    }
                    //print_r($json);
                    $i++;
                }
            }

            //print_r($jsonScripts->item(0)->nodeValue);


            libxml_use_internal_errors($old_libxml_error);

            $tags = $doc->getElementsByTagName('meta');
            if(!$tags || $tags->length === 0) {
                return FALSE;
            }

            //Get meta tags this way too as a fallback as they sometimes contain more than DOM (not sure why)
            global $siteURL;
            //$metaTags = get_meta_tags($siteURL);

            $page = new self();

            //Loop DOM nodes
            foreach($tags AS $tag) {
                if($tag->hasAttribute('property') && strpos($tag->getAttribute('property'), 'og:') === 0) {
                    $key = strtr(substr($tag->getAttribute('property'), 3), '-', '_');

                    if(array_key_exists($key, $page->_values)) {
                        if(!array_key_exists($key . '_additional', $page->_values)) {
                            $page->_values[$key . '_additional'] = array();
                        }
                        $page->_values[$key . '_additional'][] = $tag->getAttribute('content');
                    } else {
                        $page->_values[$key] = $tag->getAttribute('content');
                    }
                }

                //Added this if loop to retrieve description values from sites like the New York Times who have malformed it.
                if($tag->hasAttribute('value') && $tag->hasAttribute('property') &&
                    strpos($tag->getAttribute('property'), 'og:') === 0) {
                    $key = strtr(substr($tag->getAttribute('property'), 3), '-', '_');
                    $page->_values[$key] = $tag->getAttribute('value');
                }
                //Based on modifications at https://github.com/bashofmann/opengraph/blob/master/src/OpenGraph/OpenGraph.php
                if($tag->hasAttribute('name') && $tag->getAttribute('name') === 'description') {
                    $nonOgDescription = $tag->getAttribute('content');
                }

                //Added keywords and rating by Luke Wilson, Sept 2019
                if($tag->hasAttribute('name') && $tag->getAttribute('name') === 'keywords') {
                    $nonOgKeywords = $tag->getAttribute('content');
                }
                if($tag->hasAttribute('name') && $tag->getAttribute('name') === 'rating') {
                    $nonOgRating = $tag->getAttribute('content');
                }


                if($tag->hasAttribute('property') &&
                    strpos($tag->getAttribute('property'), 'twitter:') === 0) {
                    $key = strtr($tag->getAttribute('property'), '-:', '__');
                    $page->_values[$key] = $tag->getAttribute('content');
                }

                if($tag->hasAttribute('name') &&
                    strpos($tag->getAttribute('name'), 'twitter:') === 0) {
                    $key = strtr($tag->getAttribute('name'), '-:', '__');
                    if(array_key_exists($key, $page->_values)) {
                        if(!array_key_exists($key . '_additional', $page->_values)) {
                            $page->_values[$key . '_additional'] = array();
                        }
                        $page->_values[$key . '_additional'][] = $tag->getAttribute('content');
                    } else {
                        $page->_values[$key] = $tag->getAttribute('content');
                    }
                }
                //Added by Luke Wilson (Sept 2019) to pick up certain og: meta tags that sometimes get missed
                if($tag->hasAttribute('name') && strpos($tag->getAttribute('name'), 'og:') === 0) {
                    $key = strtr(substr($tag->getAttribute('name'), 3), '-', '_');

                    if(array_key_exists($key, $page->_values)) {
                        if(!array_key_exists($key . '_additional', $page->_values)) {
                            $page->_values[$key . '_additional'] = array();
                        }
                        $page->_values[$key . '_additional'][] = $tag->getAttribute('content');
                    } else {
                        $page->_values[$key] = $tag->getAttribute('content');
                    }
                }

                // Notably this will not work if you declare type after you declare type values on a page.
                if(array_key_exists('type', $page->_values)) {
                    $meta_key = $page->_values['type'] . ':';
                    if($tag->hasAttribute('property') && strpos($tag->getAttribute('property'), $meta_key) === 0) {
                        $meta_key_len = strlen($meta_key);
                        $key = strtr(substr($tag->getAttribute('property'), $meta_key_len), '-', '_');
                        $key = $page->_values['type'] . '_' . $key;

                        if(array_key_exists($key, $page->_values)) {
                            if(!array_key_exists($key . '_additional', $page->_values)) {
                                $page->_values[$key . '_additional'] = array();
                            }
                            $page->_values[$key . '_additional'][] = $tag->getAttribute('content');
                        } else {
                            $page->_values[$key] = $tag->getAttribute('content');
                        }
                    }
                }
            }

            $nodes = $doc->getElementsByTagName('link');
            foreach($nodes as $node) {
                if($node->getAttribute('rel') === 'canonical') {
                    $nonOgURL = $node->getAttribute('href');
                }
            }

            //fix sitename from URL
            if(!isset($page->_values['site_name'])) {
                $thisUrl = parse_url($siteURL);
                $tidyURL = preg_replace('/(www[0-9]?\.)/', '', $thisUrl['host']);
                $page->_values['site_name'] = ltrim($tidyURL, ".");
            }

            if(isset($page->_values['type'])) {
                switch($page->_values['type']) {
                    case "website":
                        $siteType = "website";
                        break;
                    case "video":
                    case "video.movie":
                        $siteType = "video";
                        if(isset($page->_values['duration'])) {
                            $getLength = round(($page->_values['duration'] / 60)) . "min";
                            $page->_values['duration_minute'] = $getLength;
                        }
                        $page->_values['video_type'] = $siteType . " " . $getLength;
                        break;
                    default:
                        $siteType = "website";
                }
                $page->_values['type'] = $siteType;
            }

            //Based on modifications at https://github.com/bashofmann/opengraph/blob/master/src/OpenGraph/OpenGraph.php
            if(!isset($page->_values['title'])) {
                $titles = $doc->getElementsByTagName('title');
                if($titles->length > 0) {
                    $page->_values['title'] = $titles->item(0)->textContent;
                }
            }

            if(!isset($page->_values['description']) && $nonOgDescription) {
                $page->_values['description'] = $nonOgDescription;
            }

            //Keyword fallback added by Luke Wilson, Sept 2019
            if(!isset($page->_values['keywords']) && $nonOgKeywords) {
                $page->_values['keywords'] = $nonOgKeywords;
            }

            //Rating fallback added by Luke Wilson, Sept 2019
            if(!isset($page->_values['rating']) && $nonOgRating) {
                $page->_values['rating'] = $nonOgRating;
            }


            //Added by Luke Wilson, Sept 2019 as a URL fallback
            if(!isset($page->_values['url']) && $nonOgURL) {
                $page->_values['url'] = $nonOgURL; //gets canonical URL
            } else {
                global $siteURL;
                $page->_values['url'] = $siteURL; //fallback to posted URL from user
            }


            //Display prices if set (Luke Wilson, Sept 2019)
            if(isset($page->_values['product_price:amount'])) {
                if(isset($page->_values['price:currency'])) {
                    $currency = $page->_values['price:currency'];
                    //browser or user locale
                    $userLocale = preg_replace("/(,[a-z]+;[a-z=0-9\.]+)/", "", $_SERVER['HTTP_ACCEPT_LANGUAGE']); // en-GB,en;q=0.8
                    if(empty($userLocale)) {
                        $userLocale = "en-GB";
                    }
                    $fmt = new NumberFormatter($userLocale . "@currency=$currency", NumberFormatter::CURRENCY);
                    $symbol = $fmt->getSymbol(NumberFormatter::CURRENCY_SYMBOL);
                }
                $page->_values['price'] = $symbol . $page->_values['product_price:amount'];
            } elseif(isset($page->_values['twitter_data1'])) {
                $page->_values['price'] = $page->_values['twitter_data1'];
            }

            //Test image size to see if it meets minimum width of 200px (Luke Wilson, Sept 2019(
            if(isset($page->_values['image'])) {
                list($width, $height, $type, $attr) = @getimagesize($page->_values['image']);
                if($width < 300) {
                    unset($page->_values['image']);
                }
            }


            //Fallback to use image_src if ogp::image isn't set.
            if(!isset($page->_values['image'])) {
                $domxpath = new DOMXPath($doc);
                $elements = $domxpath->query("//link[@rel='image_src']");

                if($elements->length > 0) {
                    $domattr = $elements->item(0)->attributes->getNamedItem('href');
                    if($domattr) {
                        $page->_values['image'] = $domattr->value;
                        $page->_values['image_src'] = $domattr->value;
                    }

                } else if(!empty($page->_values['twitter_image'])) {
                    $page->_values['image'] = $page->_values['twitter_image'];
                } else if($nonOgImg) {
                    //Added by Luke Wilson, Sept 2019
                    //Get image from JSON+LD
                    $page->_values['image'] = $nonOgImg;
                } else {
                    //Final fallbacks
                    $elementMetaImg = $doc->getElementsByTagName("meta");
                    foreach($elementMetaImg as $itemprop) {
                        if($itemprop->hasAttribute('itemprop') && $itemprop->getAttribute('itemprop') == "image") {
                            if(!preg_match('/^http/', $itemprop->getAttribute('content'))) {
                                $insertURL = rtrim($page->_values['url'], "/");
                            } else {
                                $insertURL = "";
                            }
                            $newImgUrl = $insertURL . "/" . ltrim($itemprop->getAttribute('content'), "/");
                            $page->_values['image'] = $newImgUrl;
                            list($width, $height, $type, $attr) = @getimagesize($newImgUrl); //suppression is bad, I know, I know...
                            $page->_values['image:width'] = $width;
                            $page->_values['image:height'] = $height;
                            break;
                        }
                    }
                    //If this fails, look for an <img> tag...
                    $elements = $doc->getElementsByTagName("img");
                    foreach($elements as $tag) {
                        if(preg_match('#^//#', $tag->getAttribute('src'))){
                            $imgSrc = "http:".$tag->getAttribute('src');
                        }
                        list($width, $height, $type, $attr) = @getimagesize($imgSrc);
                        if(($tag->hasAttribute('width') && ($tag->getAttribute('width') >= 300) || ($tag->getAttribute('width') == '100%')) || $width >= 300) {
                            if($width >= 300 || $tag->getAttribute('width') >= 300) {
                                if(empty($width)) {
                                    $width = $tag->getAttribute('width');
                                }
                                if(empty($height)) {
                                    $height = $tag->getAttribute('$height');
                                }
                                $page->_values['image:width'] = $width;
                                $page->_values['image:height'] = $height;
                                $page->_values['image'] = $tag->getAttribute('src');
                                break;
                            }
                        }
                        //final fallback if all else fails...
                        if(empty($page->_values['image'])) {
                            //todo: change this URL when it's live
                            $page->_values['image'] = "https://dev.webbossuk.com/List-it/img/static.jpg";
                        }
                    }
                }
            }

            //Fix relative URL image tags (Luke Wilson, Sept 2019)
            if(isset($page->_values['image'])) {
                if(!preg_match('#^(http|//)#', $page->_values['image'])) {
                    global $siteURL;
                    $thisUrl = parse_url($siteURL);
                    if(!empty($thisUrl['scheme'])) {
                        $scheme = $thisUrl['scheme'] . "://";
                    }
                    $page->_values['image'] = $scheme . rtrim($thisUrl['host'],"/") ."/". $page->_values['image'];
                }
            }

            if(empty($page->_values)) {
                return FALSE;
            }

            return $page;
        }

        static public function parse($HTML) {
            if(empty($HTML)) {
                return FALSE;
            }
            $response = mb_convert_encoding($HTML, 'HTML-ENTITIES', 'UTF-8');

            return self::_parse($response);
        }

        /**
         * Helper method to access attributes directly
         * Example:
         * $graph->title
         *
         * @param $key    Key to fetch from the lookup
         */
        public function __get($key) {
            if(array_key_exists($key, $this->_values)) {
                return $this->_values[$key];
            }

            if($key === 'schema') {
                foreach(self::$TYPES AS $schema => $types) {
                    if(array_search($this->_values['type'], $types)) {
                        return $schema;
                    }
                }
            }
        }

        /**
         * Return all the keys found on the page
         *
         * @return array
         */
        public function keys() {
            return array_keys($this->_values);
        }

        /**
         * Helper method to check an attribute exists
         *
         * @param $key
         */
        public function __isset($key) {
            return array_key_exists($key, $this->_values);
        }

        /**
         * Will return true if the page has location data embedded
         *
         * @return boolean Check if the page has location data
         */
        public function hasLocation() {
            if(array_key_exists('latitude', $this->_values) && array_key_exists('longitude', $this->_values)) {
                return TRUE;
            }

            $address_keys = array(
                'street_address',
                'locality',
                'region',
                'postal_code',
                'country_name'
            );
            $valid_address = TRUE;
            foreach($address_keys AS $key) {
                $valid_address = ($valid_address && array_key_exists($key, $this->_values));
            }

            return $valid_address;
        }

        public function rewind() {
            reset($this->_values);
            $this->_position = 0;
        }

        public function current() { return current($this->_values); }

        public function key() { return key($this->_values); }

        public function next() {
            next($this->_values);
            ++$this->_position;
        }

        public function valid() { return $this->_position < sizeof($this->_values); }
    }
