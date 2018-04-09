<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');

defined('BASEPATH') OR exit('No direct script access allowed');

class Receiver extends CI_Controller {
	public function __construct() {
		parent::__construct();
		$this->load->database();
		$this->load->helper('url');
	}

	private $storagedir = './storage';
	
	private function makeId($length = 10) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyz';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}
	
	private function makeFileName($filename) {
		$name = $this->makeId(3);

		if(file_exists($filename)) {
			$name = substr(md5_file($filename), 0, 8) . '-' . $name;
		} else {
			$name = "unknown-" . $name;
		}

		return $name;
	}
	
	private function startsWith($haystack, $needle) {
		$length = strlen($needle);
		return (substr($haystack, 0, $length) === $needle);
	}

	private function endsWith($haystack, $needle) {
		$length = strlen($needle);

		return $length === 0 || 
		(substr($haystack, -$length) === $needle);
	}
	
	private function getDatetime() {
		return date("Y-m-d h:i:s");
	}

	public function index()
	{
		$this->load->view('welcome_message');
	}

	public function send() {
		$fileinfo = array();
		$fileexists = false;

		$filefield = "contents";
		$uploaddir = $this->storagedir;
		$uploadfile = "";
		$tmpfile = "";

		$res = array(
			"success" => false,
			"message" => "Unknown error"
		);

		if(count($_FILES) > 0 && array_key_exists($filefield, $_FILES)) {
			$fileexists = true;
		}

		if($fileexists == true) {
			$fileinfo = $_FILES[$filefield];
			$tmpfile = $_FILES[$filefield]["tmp_name"];
			$uploadfile = $uploaddir . '/' . $this->makeFileName($tmpfile);

			if(move_uploaded_file($tmpfile, $uploadfile)) {
				$res = $this->aftersend($uploadfile);
			} else {
				$res["message"] = "Failed upload file";
			}
			
			unlink($uploadfile);
		} else {
			$res["message"] = "Not valid request";
		}

		echo json_encode($res);

		return $res;
	}

	private function aftersend($filename) {
		$res = array(
			"success" => false,
			"message" => "failed file processing"
		);

		$contents = "";
		$contents_escaped = "";
		$fh = fopen($filename, 'r');
		if($fh !== false) {
			$contents = fread($fh, filesize($filename));
			fclose($fh);
		} else {
			$res["message"] = "file not exists";
		}

		$contents_escaped = gzinflate($contents);
		if(strlen($contents_escaped) > 0) {
			$tmph = tmpfile();
			fwrite($tmph, $contents_escaped);

			$tmpm = stream_get_meta_data($tmph);
			$tmpfile = $tmpm['uri'];
			$dstfile = $this->storagedir . '/' . $this->makeFileName($tmpfile);
			copy($tmpfile, $dstfile);

			$this->parsegoogle($dstfile); // will be seperate
			fclose($tmph);

			$res["success"] = true;
			$res["message"] = "Success file processing";

			unlink($dstfile);
		}

		return $res;
	}
	
	private function loadDom($uri) {
		$html = file_get_html($uri);
		if(!$html) {
			$html = $this->loadDom($uri);
		}
		return $html;
	}

	private function parsegoogle($uri) {
		if($this->input->post("instanceid")) {
			$instance_id = $this->input->post("instanceid");
		} else {
			$instance_id = $this->makeId(6);
		}
		$keyword = $this->input->post('keyword');
		$searchurl = $this->input->post('searchurl');
		
		$res = array(
			"success" => false,
			"message" => "failed parse google results"
		);
		
		if(file_exists($uri)) {
			echo "file exists: " . $uri;
		}

		// load dom library
		$inc_file = './application/libraries/simple_html_dom.php';
		if(file_exists($inc_file)) {
			include_once($inc_file);
		} else {
			$res["message"] = "simple_html_dom library does not exists";
		}
		
		// found links
		$found_links = array();

		// parse markup
		$html = $this->loadDom($uri);
		$linkObjs = $html->find('h3.r a');
		$rank = 0;

		// get last rank
		$query = $this->db->query("select max(unit_rank) as max_rank from gunit where instance_id = '{$instance_id}'");
		$queryResult = $query->result();
		if(count($queryResult) > 0) {
			foreach($queryResult as $row) {
				$rank = $row->max_rank;
			}
		}

		foreach ($linkObjs as $linkObj) {
			$title = trim($linkObj->plaintext);
			$link  = trim($linkObj->href);
			
			// if it is not a direct link but url reference found inside it, then extract
			if (!preg_match('/^https?/', $link) && preg_match('/q=(.+)&amp;sa=/U', $link, $matches) && preg_match('/^https?/', $matches[1])) {
				$link = $matches[1];
			} else if (!preg_match('/^https?/', $link)) { // skip if it is not a valid link
				continue;
			}
			
			// store found links
			$found_links[] = array(
				"title"   => $title,
				"link"    => $link,
				"rank"    => ++$rank
			);
		}

		// get work record
		$check_domain = "";
		$work_id = 0;
		$query = $this->db->query("select * from gunit_works where instance_id = '{$instance_id}'");
		foreach($query->result() as $row) {
			$check_domain = $row->work_domain;
			$work_id = $row->work_id;
		}

		// write to db
		foreach($found_links as $link) {
			$insert_data = array(
				"instance_id"    => $instance_id,
				"unit_keyword"   => $keyword,
				"unit_title"     => $link['title'],
				"unit_link"      => $link['link'],
				"unit_rank"      => $link['rank'],
				"unit_searchurl" => $searchurl,
				"unit_commit"     => 0,
				"unit_datetime"  => $this->getDatetime()
			);
			$this->db->insert('gunit', $insert_data);			
			$unit_id = $this->db->insert_id();
			
			// detect matched domain
			// example: https://google.com
			$link_blocks = explode('/', $link['link']);
			$link_domain = $link_blocks[2];
			$link_matched = 0;
			if(!empty($check_domain) && $this->endsWith($link_domain, $check_domain)) {
				$link_matched = 1;
			}

			// save detected matches
			$this->db->insert("gunit_matched", array(
				"unit_id"        => $unit_id,
				"work_id"        => $work_id,
				"matched_domain" => $check_domain,
				"matched_result" => $link_matched
			));
		}

		// export message
		$res["success"] = true;
		$res["message"] = "success parse google results";

		echo "\n";
		print_r($_POST);
		
		return $res;
	}
	
	public function works($out=true) {
		$items = array();

		// work_id, instance_id, work_keyword, work_domain, work_country, work_stop, work_status, work_datetime, work_last 
		$works_table = "gunit_works";
		$query = $this->db->query("select * from {$works_table} where work_status < 1");
		foreach($query->result() as $row) {
			if(!empty($row->work_keyword)) {
				$items[] = $row;
			}
		}

		// write mutex flag
		$this->db->update($works_table, array(
			"work_mutex" => 1
		));

		if($out == true) {
			echo json_encode(array("works" => $items));
		}
		
		return $items;
	}
	
	public function setstatus($out=true) {
		$res = array(
			"success" => false,
			"message" => "Something wrong"
		);

		$instance_id = $this->input->get("instance_id");
		$status = $this->input->get("status");

		$data = array(
			"instance_id" => $instance_id,
			"work_status" => $status,
			"work_last"   => date("Y-m-d h:i:s")
		);

		$this->db->where('instance_id', $instance_id);
		if($this->db->update('gunit_works', $data)) {
			$res["success"] = true;
			$res["message"] = "Reqeusted OK";
		}

		// commit to GRank UI
		$this->commit();
		
		if($out == true) {
			echo json_encode($res);
		}

		return $res;
	}

	public function getresult($out=true) {
		$results = array();

		$instance_id = $this->input->get("instance_id");
		$query = $this->db->query("select * from gunit where instance_id = '{$instance_id}' order by unit_id desc");
		foreach($query->result() as $row) {
			// get matched flag
			$row->matched_result = 0;
			$row->matched_domain = "";
			$row->work_country = "";

			$unit_id = $row->unit_id;
			$query2 = $this->db->query("select * from gunit_matched where unit_id = '{$unit_id}'");
			foreach($query2->result() as $row2) {
				$row->matched_result = $row2->matched_result;
				$row->matched_domain = $row2->matched_domain;

				// get country
				$work_id = $row2->work_id;
				$query3 = $this->db->query("select * from gunit_works where work_id = '{$work_id}'");
				foreach($query3->result() as $row3) {
					$row->work_country = $row3->work_country;
				}
			}

			$results[] = $row;
		}

		$res = array(
			"success" => true,
			"instance_id" => $instance_id,
			"results" => $results
		);

		if($out == true) {
			echo json_encode($res);
		}

		// commit to GRank UI
		$this->commit();

		return $res;
	}
	
	public function creatework($out=true) {
		$res = array(
			"success" => false,
			"message" => "Something wrong"
		);

		$instance_id = $this->input->post("instance_id");
		if(!empty($instance_id)) {
			$work_stop = $this->input->post("work_stop");
			$work_stop = empty($work_stop) ? 20 : $work_stop;

			$data = array(
				"instance_id"   => $this->input->post("instance_id"),
				"work_keyword"  => $this->input->post("work_keyword"),
				"work_domain"   => $this->input->post("work_domain"),
				"work_country"  => $this->input->post("work_country"),
				"work_stop"     => $work_stop,
				"work_status"   => 0,
				"work_mutex"    => 0,
				"work_datetime" => date("Y-m-d h:i:s"),
				"work_last"     => date("Y-m-d h:i:s")
			);
			$this->db->insert('gunit_works', $data);

			$res = array(
				"success" => true,
				"instance_id" => $instance_id,
				"message" => "작업이 등록되었습니다."
			);
		}
		
		if($out == true) {
			echo json_encode($res);
		}
		
		return $res;
	}

	public function commit() {
		// Commit activity data to GRank UI
		// 완료된 작업만 넘김
		$query = $this->db->query("select * from gunit_works where work_status > 0");
		foreach($query->result() as $row) {
			// get result in match table
			$instance_id = $row->instance_id;
			$work_id = $row->work_id;
			$work_matched = 0;
			$work_country = $row->work_country;
			$work_keyword = $row->work_keyword;
			$query2 = $this->db->query("select * from gunit_matched where work_id = {$work_id}");
			foreach($query2->result() as $row2) {
				$matched_result = $row2->matched_result;
				$matched_domain = $row2->matched_domain;
				$unit_id = $row2->unit_id;
				$query3 = $this->db->query("select * from gunit where unit_id = '{$unit_id}' and unit_commit = 0");
				foreach($query3->result() as $row3) {
					$unit_link = $row3->unit_link;
					$unit_rank = $row3->unit_rank;

					$profile_id = 1; // admin (temporary)
					$keyword_id = 0;
					$query4 = $this->db->query("select * from grank_keyword where keyword_name = '{$work_keyword}'");
					foreach($query4->result() as $row4) {
						$keyword_id = $row4->keyword_id;
					}
					
					// if not keyword registered
					if($keyword_id == 0) {
						$keyword_data = array(
							"profile_id"       => $profile_id,
							"keyword_type"     => "manual",
							"keyword_name"     => $work_keyword,
							"keyword_domain"   => $work_domain,
							"keyword_datetime" => $this->getDatetime()
						);
						$this->db->insert("grank_keyword", $keyword_data);
						$keyword_id = $this->db->insert_id();
					}					

					// write activity data
					$activity_data = array(
						"profile_id"            => $profile_id,
						"keyword_id"            => $keyword_id,
						"activity_code"         => $instance_id,
						"activity_type"         => "manual",
						"activity_rank"         => $unit_rank,
						"activity_domain"       => $matched_domain,
						"activity_matched"      => $matched_result,
						"activity_url"          => $unit_link,
						"activity_country_code" => $work_country,
						"activity_description"  => "GUnit 1.0",
						"activity_datetime"     => $this->getDatetime()
					);
					$this->db->insert("grank_activity", $activity_data);
					
					// write commit flag
					$this->db->query("update gunit set unit_commit = 1 where unit_id = '{$unit_id}'");
				}
			}
		}
	}
}
