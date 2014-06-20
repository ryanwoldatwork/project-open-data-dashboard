<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Offices extends CI_Controller {




	function __construct() {
		parent::__construct();

		$this->load->helper('url');
	}

	/**
	 * Index Page for this controller.
	 *
	 * Maps to the following URL
	 * 		http://example.com/index.php/welcome
	 *	- or -
	 * 		http://example.com/index.php/welcome/index
	 *	- or -
	 * Since this controller is set as the default controller in
	 * config/routes.php, it's displayed at http://example.com/
	 *
	 * So any other public methods not prefixed with an underscore will
	 * map to /index.php/welcome/<method_name>
	 * @see http://codeigniter.com/user_guide/general/urls.html
	 */
	public function index($milestone = null, $output=null, $show_all_offices = false)
	{

		$this->load->model('campaign_model', 'campaign');
		$milestones = $this->campaign->milestones_model();	
		$selected_milestone	= ($this->input->get_post('milestone', TRUE)) ? $this->input->get_post('milestone', TRUE) : $milestone;

		$milestone 			= $this->milestone_filter($selected_milestone, $milestones);
		$milestones 		= $milestone['milestones'];
		$selected_milestone = $milestone['selected_milestone'];

		$view_data = array();

		$this->db->select('*');
		$this->db->from('offices');
		$this->db->join('datagov_campaign', 'datagov_campaign.office_id = offices.id', 'left');	
		$this->db->where('datagov_campaign.milestone', $selected_milestone);
		$this->db->where('offices.cfo_act_agency', 'true');
		$this->db->where('offices.no_parent', 'true');
		$this->db->order_by("offices.name", "asc");
		$query = $this->db->get();

		if ($query->num_rows() > 0) {
			$view_data['cfo_offices'] = $query->result();
			$query->free_result();
		}

		if ($this->config->item('show_all_offices') || $show_all_offices) {

			$this->db->select('*');
			$this->db->from('offices');
			$this->db->join('datagov_campaign', 'datagov_campaign.office_id = offices.id', 'left');
			$this->db->where('offices.cfo_act_agency', 'false');
			$this->db->where('offices.reporting_authority_type', 'executive');
			$this->db->where('offices.no_parent', 'true');
			$this->db->order_by("offices.name", "asc");
			$query = $this->db->get();

			if ($query->num_rows() > 0) {
			   $view_data['executive_offices'] = $query->result();
			   $query->free_result();
			}


			$this->db->select('*');
			$this->db->from('offices');
			$this->db->join('datagov_campaign', 'datagov_campaign.office_id = offices.id', 'left');
			$this->db->where('offices.cfo_act_agency', 'false');
			$this->db->where('offices.reporting_authority_type', 'independent');
			$this->db->where('offices.no_parent', 'true');
			$this->db->order_by("offices.name", "asc");
			$query = $this->db->get();


			// if successful return ocdid
			if ($query->num_rows() > 0) {
			   $view_data['independent_offices'] = $query->result();
			   $query->free_result();
			}

		}

		// pass milestones data model
		$view_data['milestones'] = $milestones;
		$view_data['selected_milestone'] = $selected_milestone;		

		// pass config variable
		$view_data['max_remote_size'] = $this->config->item('max_remote_size');

		if ($output == 'json') {
			return $view_data;
		}

		$this->load->view('office_list', $view_data);
	}


	public function export() {
		$listing = $this->index('json');

		$output = array();

		foreach ($listing as $group) {

			foreach ($group as $office) {
				$output[] = array("key" => $office->id, "name" => $office->name);
			}

		}

		header('Content-type: application/json');
		print json_encode($output);
		exit;

	}


	public function detail($id, $milestone=null) {

		$this->load->helper('api');
		$this->load->model('campaign_model', 'campaign');
		$this->load->library('markdown');

		$milestones = $this->campaign->milestones_model();	
		$selected_milestone	= ($this->input->get_post('milestone', TRUE)) ? $this->input->get_post('milestone', TRUE) : $milestone;

		$selected_category	= ($this->input->get_post('highlight', TRUE)) ? $this->input->get_post('highlight', TRUE) : null;
	
		$milestone 			= $this->milestone_filter($selected_milestone, $milestones);
		$milestones 		= $milestone['milestones'];
		$selected_milestone = $milestone['selected_milestone'];

		$this->db->select('*');
		$this->db->where('id', $id);
		$query = $this->db->get('offices');

		$view_data = array();

		if ($query->num_rows() > 0) {
		   $view_data['office'] = $query->row();


			// Get note data
			$notes = $this->campaign->get_notes($view_data['office']->id, $selected_milestone);
			$view_data['note_model'] = $this->campaign->note_model();

			if ($notes->num_rows() > 0) {

				$note_list = array();
				foreach ($notes->result() as $note) {
					$note_field = 'note_' . $note->field_name;
					$note_list[$note_field] = json_decode($note->note);
					if(!empty($note_list[$note_field]->current->note)) {

						$note_html = $note_list[$note_field]->current->note;
						$note_html = linkToAnchor($note_html);
						$note_list[$note_field]->current->note_html =  $this->markdown->parse($note_html);
					} else {
						$note_list[$note_field]->current->note_html = null;
					}
				}

				$view_data['notes'] = $note_list;
			}

			// Get crawler data
			$view_data['office_campaign'] = $this->campaign->datagov_office($view_data['office']->id, $selected_milestone);

			if(empty($view_data['office_campaign'])) {
				$view_data['office_campaign'] = $this->campaign->datagov_model();
			}


			if(!empty($view_data['office_campaign']->datajson_status)) {
				$view_data['office_campaign']->expected_datajson_url = (!empty($view_data['office_campaign']->datajson_status['url'])) ? $view_data['office_campaign']->datajson_status['url'] : '';
				$view_data['office_campaign']->expected_datajson_status = (object) json_decode($view_data['office_campaign']->datajson_status);
			}

			if ($this->config->item('show_all_offices')) {

				// Get sub offices
				$this->db->select('*');
				$this->db->from('offices');
				$this->db->join('datagov_campaign', 'datagov_campaign.office_id = offices.id', 'left');
				$this->db->where('offices.parent_office_id', $view_data['office']->id);
				$this->db->order_by("offices.name", "asc");
				$query = $this->db->get();

				if ($query->num_rows() > 0) {
				   $view_data['child_offices'] = $query->result();
				}

			}



		}

		// pass milestones data model
		$view_data['milestones'] = $milestones;
		$view_data['selected_milestone'] = $selected_milestone;

		// selected tab
		$view_data['selected_category'] = $selected_category;

		// pass tracker data model
		$view_data['tracker_model'] = $this->campaign->tracker_model();

		// pass config variable
		$view_data['config'] = array('max_remote_size' => $this->config->item('max_remote_size'));

		//var_dump($view_data['office_campaign']); exit;

		$this->load->view('office_detail', $view_data);

	}

	public function routes($route, $parameter1 = null, $parameter2 = null) {

		if($route == 'all') {
			return $this->index($milestone=null, $output=null, $show_all_offices = true);	
		}

		if($route == 'detail') {
			return $this->detail($parameter1, $parameter2);	
		}

		// check if it's a milestone date
    	$d = DateTime::createFromFormat('Y-m-d', $route);
    	if ($d && $d->format('Y-m-d') == $route) {
    		return $this->index($milestone=$route, $output=null, $show_all_offices = false);	
    	}


		
	}


	public function milestone_filter($selected_milestone, $milestones) {

		// Sets the first milestone in the future as the current and last available milestone
	    foreach ($milestones as $milestone_date => $milestone) {
	        if (strtotime($milestone_date) > time()) {
	            
	        	if(empty($current_milestone)) {
	        		$current_milestone = $milestone_date;	
	        	} else {
	        		unset($milestones[$milestone_date]);
	        	}	            
	        } 
	    }

	    // if we didn't explicitly select a milestone, use the current one
		if(empty($selected_milestone)) {
			$selected_milestone = $current_milestone;
		}

		reset($milestones);

		$response = array();

		$response['selected_milestone'] = $selected_milestone;
		$response['milestones'] 		= $milestones;

		return $response;

	}



}

/* End of file welcome.php */
/* Location: ./application/controllers/welcome.php */