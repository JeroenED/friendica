<?php

/**
 * @file src/Content/OEmbed.php
 */

namespace Friendica\Content;

use Friendica\Core\Cache;
use Friendica\Core\System;
use Friendica\ParseUrl;
use Friendica\Core\Config;
use Friendica\Database\DBM;
use dba;
use DOMDocument;
use DOMXPath;
use DOMNode;

require_once 'include/dba.php';
require_once 'mod/proxy.php';

/**
 * Handles all OEmbed content fetching and replacement
 *
 * OEmbed is a standard used to allow an embedded representation of a URL on
 * third party sites
 *
 * @see https://oembed.com
 *
 * @author Hypolite Petovan <mrpetovan@gmail.com>
 */
class OEmbed
{
	public static function replaceCallback($matches)
	{
		$embedurl = $matches[1];
		$j = self::fetchURL($embedurl);
		$s = self::formatObject($j);

		return $s;
	}

	/**
	 * @brief Get data from an URL to embed its content.
	 *
	 * @param string $embedurl The URL from which the data should be fetched.
	 * @param bool $no_rich_type If set to true rich type content won't be fetched.
	 *
	 * @return bool|object Returns object with embed content or false if no embedable
	 * 	 content exists
	 */
	public static function fetchURL($embedurl, $no_rich_type = false)
	{
		$embedurl = trim($embedurl, "'");
		$embedurl = trim($embedurl, '"');

		$a = get_app();

		$condition = array('url' => normalise_link($embedurl));
		$r = dba::select('oembed', array('content'), $condition, array('limit' => 1));

		if (DBM::is_result($r)) {
			$txt = $r["content"];
		} else {
			$txt = Cache::get($a->videowidth . $embedurl);
		}
		// These media files should now be caught in bbcode.php
		// left here as a fallback in case this is called from another source

		$noexts = array("mp3", "mp4", "ogg", "ogv", "oga", "ogm", "webm");
		$ext = pathinfo(strtolower($embedurl), PATHINFO_EXTENSION);


		if (is_null($txt)) {
			$txt = "";

			if (!in_array($ext, $noexts)) {
				// try oembed autodiscovery
				$redirects = 0;
				$html_text = fetch_url($embedurl, false, $redirects, 15, "text/*");
				if ($html_text) {
					$dom = @DOMDocument::loadHTML($html_text);
					if ($dom) {
						$xpath = new DOMXPath($dom);
						$entries = $xpath->query("//link[@type='application/json+oembed']");
						foreach ($entries as $e) {
							$href = $e->getAttributeNode("href")->nodeValue;
							$txt = fetch_url($href . '&maxwidth=' . $a->videowidth);
							break;
						}
						$entries = $xpath->query("//link[@type='text/json+oembed']");
						foreach ($entries as $e) {
							$href = $e->getAttributeNode("href")->nodeValue;
							$txt = fetch_url($href . '&maxwidth=' . $a->videowidth);
							break;
						}
					}
				}
			}

			$txt = trim($txt);

			if (!$txt || $txt[0] != "{") {
				$txt = '{"type":"error"}';
			} else { //save in cache
				$j = json_decode($txt);
				if ($j->type != "error") {
					dba::insert('oembed', array('url' => normalise_link($embedurl),
						'content' => $txt, 'created' => datetime_convert()), true);
				}

				Cache::set($a->videowidth . $embedurl, $txt, CACHE_DAY);
			}
		}

		$j = json_decode($txt);

		if (!is_object($j)) {
			return false;
		}

		// Always embed the SSL version
		if (isset($j->html)) {
			$j->html = str_replace(array("http://www.youtube.com/", "http://player.vimeo.com/"), array("https://www.youtube.com/", "https://player.vimeo.com/"), $j->html);
		}

		$j->embedurl = $embedurl;

		// If fetching information doesn't work, then improve via internal functions
		if (($j->type == "error") || ($no_rich_type && ($j->type == "rich"))) {
			$data = ParseUrl::getSiteinfoCached($embedurl, true, false);
			$j->type = $data["type"];

			if ($j->type == "photo") {
				$j->url = $data["url"];
				//$j->width = $data["images"][0]["width"];
				//$j->height = $data["images"][0]["height"];
			}

			if (isset($data["title"])) {
				$j->title = $data["title"];
			}

			if (isset($data["text"])) {
				$j->description = $data["text"];
			}

			if (is_array($data["images"])) {
				$j->thumbnail_url = $data["images"][0]["src"];
				$j->thumbnail_width = $data["images"][0]["width"];
				$j->thumbnail_height = $data["images"][0]["height"];
			}
		}

		call_hooks('oembed_fetch_url', $embedurl, $j);

		return $j;
	}

	public static function formatObject($j)
	{
		$embedurl = $j->embedurl;
		$jhtml = self::iframe($j->embedurl, (isset($j->width) ? $j->width : null), (isset($j->height) ? $j->height : null));
		$ret = "<span class='oembed " . $j->type . "'>";
		switch ($j->type) {
			case "video":
				if (isset($j->thumbnail_url)) {
					$tw = (isset($j->thumbnail_width) && intval($j->thumbnail_width)) ? $j->thumbnail_width : 200;
					$th = (isset($j->thumbnail_height) && intval($j->thumbnail_height)) ? $j->thumbnail_height : 180;
					// make sure we don't attempt divide by zero, fallback is a 1:1 ratio
					$tr = (($th) ? $tw / $th : 1);

					$th = 120;
					$tw = $th * $tr;
					$tpl = get_markup_template('oembed_video.tpl');
					$ret.=replace_macros($tpl, array(
						'$baseurl' => System::baseUrl(),
						'$embedurl' => $embedurl,
						'$escapedhtml' => base64_encode($jhtml),
						'$tw' => $tw,
						'$th' => $th,
						'$turl' => $j->thumbnail_url,
					));
				} else {
					$ret = $jhtml;
				}
				//$ret.="<br>";
				break;
			case "photo":
				$ret.= "<img width='" . $j->width . "' src='" . proxy_url($j->url) . "'>";
				break;
			case "link":
				break;
			case "rich":
				// not so safe..
				if (!Config::get("system", "no_oembed_rich_content")) {
					$ret.= proxy_parse_html($jhtml);
				}
				break;
		}

		// add link to source if not present in "rich" type
		if ($j->type != 'rich' || !strpos($j->html, $embedurl)) {
			$ret .= "<h4>";
			if (isset($j->title)) {
				if (isset($j->provider_name)) {
					$ret .= $j->provider_name . ": ";
				}

				$embedlink = (isset($j->title)) ? $j->title : $embedurl;
				$ret .= "<a href='$embedurl' rel='oembed'>$embedlink</a>";
				if (isset($j->author_name)) {
					$ret.=" (" . $j->author_name . ")";
				}
			} elseif (isset($j->provider_name) || isset($j->author_name)) {
				$embedlink = "";
				if (isset($j->provider_name)) {
					$embedlink .= $j->provider_name;
				}

				if (isset($j->author_name)) {
					if ($embedlink != "") {
						$embedlink .= ": ";
					}

					$embedlink .= $j->author_name;
				}
				if (trim($embedlink) == "") {
					$embedlink = $embedurl;
				}

				$ret .= "<a href='$embedurl' rel='oembed'>$embedlink</a>";
			}
			//if (isset($j->author_name)) $ret.=" by ".$j->author_name;
			//if (isset($j->provider_name)) $ret.=" on ".$j->provider_name;
			$ret .= "</h4>";
		} else {
			// add <a> for html2bbcode conversion
			$ret .= "<a href='$embedurl' rel='oembed'>$embedurl</a>";
		}
		$ret.="</span>";
		$ret = str_replace("\n", "", $ret);
		return mb_convert_encoding($ret, 'HTML-ENTITIES', mb_detect_encoding($ret));
	}

	public static function BBCode2HTML($text)
	{
		$stopoembed = Config::get("system", "no_oembed");
		if ($stopoembed == true) {
			return preg_replace("/\[embed\](.+?)\[\/embed\]/is", "<!-- oembed $1 --><i>" . t('Embedding disabled') . " : $1</i><!-- /oembed $1 -->", $text);
		}
		return preg_replace_callback("/\[embed\](.+?)\[\/embed\]/is", ['self', 'replaceCallback'], $text);
	}

	/**
	 * Find <span class='oembed'>..<a href='url' rel='oembed'>..</a></span>
	 * and replace it with [embed]url[/embed]
	 */
	public static function HTML2BBCode($text)
	{
		// start parser only if 'oembed' is in text
		if (strpos($text, "oembed")) {

			// convert non ascii chars to html entities
			$html_text = mb_convert_encoding($text, 'HTML-ENTITIES', mb_detect_encoding($text));

			// If it doesn't parse at all, just return the text.
			$dom = @DOMDocument::loadHTML($html_text);
			if (!$dom) {
				return $text;
			}
			$xpath = new DOMXPath($dom);

			$xattr = self::buildXPath("class", "oembed");
			$entries = $xpath->query("//span[$xattr]");

			$xattr = "@rel='oembed'"; //oe_build_xpath("rel","oembed");
			foreach ($entries as $e) {
				$href = $xpath->evaluate("a[$xattr]/@href", $e)->item(0)->nodeValue;
				if (!is_null($href)) {
					$e->parentNode->replaceChild(new DOMText("[embed]" . $href . "[/embed]"), $e);
				}
			}
			return self::getInnerHTML($dom->getElementsByTagName("body")->item(0));
		} else {
			return $text;
		}
	}

	/**
	 * @brief Generates the iframe HTML for an oembed attachment.
	 *
	 * Width and height are given by the remote, and are regularly too small for
	 * the generated iframe.
	 *
	 * The width is entirely discarded for the actual width of the post, while fixed
	 * height is used as a starting point before the inevitable resizing.
	 *
	 * Since the iframe is automatically resized on load, there are no need for ugly
	 * and impractical scrollbars.
	 *
	 * @param string $src Original remote URL to embed
	 * @param string $width
	 * @param string $height
	 * @return string formatted HTML
	 *
	 * @see oembed_format_object()
	 */
	private static function iframe($src, $width, $height)
	{
		$a = get_app();

		if (!$height || strstr($height, '%')) {
			$height = '200';
		}
		$width = '100%';

		$s = System::baseUrl() . '/oembed/' . base64url_encode($src);
		return '<iframe onload="resizeIframe(this);" class="embed_rich" height="' . $height . '" width="' . $width . '" src="' . $s . '" allowfullscreen scrolling="no" frameborder="no">' . t('Embedded content') . '</iframe>';
	}

	/**
	 * Generates an XPath query to select elements whose provided attribute contains
	 * the provided value in a space-separated list.
	 *
	 * @brief Generates attribute search XPath string
	 *
	 * @param string $attr Name of the attribute to seach
	 * @param string $value Value to search in a space-separated list
	 * @return string
	 */
	private static function buildXPath($attr, $value)
	{
		// https://www.westhoffswelt.de/blog/2009/6/9/select-html-elements-with-more-than-one-css-class-using-xpath
		return "contains(normalize-space(@$attr), ' $value ') or substring(normalize-space(@$attr), 1, string-length('$value') + 1) = '$value ' or substring(normalize-space(@$attr), string-length(@$attr) - string-length('$value')) = ' $value' or @$attr = '$value'";
	}

	/**
	 * Returns the inner XML string of a provided DOMNode
	 *
	 * @brief Returns the inner XML string of a provided DOMNode
	 *
	 * @param DOMNode $node
	 * @return string
	 */
	private static function getInnerHTML(DOMNode $node)
	{
		$innerHTML = '';
		$children = $node->childNodes;
		foreach ($children as $child) {
			$innerHTML .= $child->ownerDocument->saveXML($child);
		}
		return $innerHTML;
	}
}