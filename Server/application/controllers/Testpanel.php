<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Testpanel extends CI_Controller {
	private function makeid($length = 10) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyz';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}

	public function __construct() {
		parent::__construct();
		$this->load->database();
		$this->load->helper('url');
	}

	public function index() {
		$this->load->view('welcome_message');
	}
	
	public function taskform() {
		$results = array();

		$data = array();
		$data["base_url"] = base_url();
		$data["instance_id"] = $this->makeid(8);

		$query = $this->db->query("select * from gunit_works order by work_id desc limit 15");
		foreach($query->result() as $row) {
			$results[] = $row;
		}
		$data["results"] = $results;

		$datafields = array(
			"work_id"       => "#",
			"instance_id"   => "작업 ID",
			"work_keyword"  => "키워드",
			"work_domain"   => "도메인",
			"work_country"  => "국가구분",
			"work_stop"     => "제한",
			"work_status"   => "상태",
			"work_mutex"    => "점유",
			"work_datetime" => "요청일자",
			"work_last"     => "최근변동"
		);
		$data["datafields"] = $datafields;

		$this->load->view('templates/gunit/header', $data);
		$this->load->view('view_taskform', $data);
		$this->load->view('templates/gunit/footer', $data);
	}

	public function taskcreate($out=true) {
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
			"work_commit"   => 0,
			"work_datetime" => date("Y-m-d h:i:s"),
			"work_last"     => date("Y-m-d h:i:s")
		);
		$this->db->insert('gunit_works', $data);

		$res = array(
			"success" => true,
			"instance_id" => $data["instance_id"],
			"message" => "작업이 등록되었습니다."
		);
		
		if($out == true) {
			echo json_encode($res);
		}
		
		return $out;
	}
}
