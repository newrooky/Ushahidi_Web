<?php defined('SYSPATH') or die('No direct script access.');
/**
 * This controller handles requests for SMS/ Email alerts
 *
 * PHP version 5
 * LICENSE: This source file is subject to LGPL license 
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/lesser.html
 * @author     Ushahidi Team <team@ushahidi.com> 
 * @package    Ushahidi - http://source.ushahididev.com
 * @module     Alerts Controller  
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license    http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License (LGPL) 
 */

class Alerts_Controller extends Main_Controller 
{
    const MOBILE_ALERT = 1;
	const EMAIL_ALERT = 2;
    
    

	function __construct()
    {
        parent::__construct();
        $this->session = Session::instance();
    }

    public function index()
    {
        // Create new session
        $this->session->create();
		
        $this->template->header->this_page = 'alerts';
        $this->template->content = new View('alerts');
		
		// Display news feeds?
		$this->template->content->allow_feed = Kohana::config('settings.allow_feed');
		
        // Retrieve default country, latitude, longitude
        $default_country = Kohana::config('settings.default_country');
		
        // Retrieve Country Cities
        $this->template->content->cities = $this->_get_cities($default_country);
		
		// Setup and initialize form field names
        $form = array (
                'alert_mobile' => '',
                'alert_mobile_yes' => '',
                'alert_email' => '',
                'alert_email_yes' => '',
                'alert_lat' => '',
                'alert_lon' => ''
        	);

        // Copy the form as errors, so the errors will be stored with keys
        // corresponding to the form field names
        $errors = $form;
        $form_error = FALSE;
        $form_saved = FALSE;
		
        // If there is a post and $_POST is not empty
		if ($post = $this->input->post())
		{
			// Create a new alert
			$alert = ORM::factory('alert');			
			
            // Test to see if things passed the rule checks
            if ($alert->validate($post))
            {
                // Yes! everything is valid
				// Save alert and send out confirmation code
                $email_confirmation_saved = FALSE;
                $sms_confirmation_saved = FALSE;

				if (!empty($post->alert_mobile))
				{
        			$sms_confirmation_saved =
						$this->_send_mobile_alert($alert, $post->alert_mobile,
								$post->alert_lon, $post->alert_lat);
				}

				if (!empty($post->alert_email))
				{
					$email_confirmation_saved =
						$this->_send_email_alert($alert, $post->alert_email,
								$post->alert_lon, $post->alert_lat);
				}

                $this->session->set('alert_mobile', $post->alert_mobile);
                $this->session->set('alert_email', $post->alert_email);
				$this->session->set('sms_confirmation_saved',
									$sms_confirmation_saved);
                $this->session->set('email_confirmation_saved',
									$email_confirmation_saved);
                
				url::redirect('alerts/confirm');					
            }
            // No! We have validation errors, we need to show the form again, with the errors
            else
            {
                // repopulate the form fields
                $form = arr::overwrite($form, $post->as_array());

                // populate the error fields, if any
                $errors = arr::overwrite($errors, $post->errors('alerts'));
                $form_error = TRUE;
            }
        }
        else
        {
            $form['alert_lat'] = Kohana::config('settings.default_lat');
            $form['alert_lon'] = Kohana::config('settings.default_lon');
        }
		
        $this->template->content->form = $form;
        $this->template->content->errors = $errors;
        $this->template->content->form_error = $form_error;
        $this->template->content->form_saved = $form_saved;
		
        // Javascript Header
        $this->template->header->map_enabled = TRUE;
        $this->template->header->js = new View('alerts_js');
        $this->template->header->js->default_map = Kohana::config('settings.default_map');
        $this->template->header->js->default_zoom = Kohana::config('settings.default_zoom');
        $this->template->header->js->latitude = $form['alert_lat'];
        $this->template->header->js->longitude = $form['alert_lon'];
    }

	
    /**
     * Alerts Confirmation Page
     */
    function confirm()
    {
        $this->template->content = new View('alerts_confirm');

		if (isset($_SESSION['alert_mobile']) && isset($_SESSION['alert_email']))
		{
			$this->template->content->alert_mobile = $_SESSION['alert_mobile'];
			$this->template->content->alert_email = $_SESSION['alert_email'];
		}

		$this->template->content->email_confirmation_saved =
			isset($_SESSION['email_confirmation_saved']) 
			    ? $_SESSION['email_confirmation_saved'] : FALSE;
		$this->template->content->sms_confirmation_saved =
			isset($_SESSION['sms_confirmation_saved']) 
			    ? $_SESSION['sms_confirmation_saved'] : FALSE;
    }
    
    
    /**
     * Verifies a previously sent alert confirmation code
     * 
     * @param string $code
     */
    public function verify($code = NULL)
    {   
        
        // Define error codes for this view.
        define("ER_CODE_VERIFIED", 0);
	    define("ER_CODE_NOT_FOUND", 1);
	    define("ER_CODE_ALREADY_VERIFIED", 3);
        
        // INITIALIZE the content's section of the view
       	$this->template->content = new View('alerts_verify');
        $this->template->header->this_page = 'alerts';
       
		if ($code != NULL)
		{
            $alert_code = ORM::factory('alert')
                            ->where('alert_code', $code)
                            ->find();

            // IF there was no result
            if (!$alert_code->loaded)
            {
                $this->template->content->errno = ER_CODE_NOT_FOUND;
            }
            elseif ($alert_code->alert_confirmed)
            {
                $this->template->content->errno = ER_CODE_ALREADY_VERIFIED;
            }
            else 
            {
                // SET the alert as confirmed, and save it
		        $alert_code->set('alert_confirmed', 1)->save();
                $this->template->content->errno = ER_CODE_VERIFIED;
            }
		}
		else
		{
			$this->template->content->errno = self::ER_CODE_NOT_FOUND;
		}
	} // END function verify

	/**
     * Unsubscribes alertee using alertee's confirmation code
     * 
     * @param string $code
     */
	public function unsubscribe($code = NULL)
	{
       	$this->template->content = new View('alerts_unsubscribe');
        $this->template->header->this_page = 'alerts';
		$this->template->content->unsubscribed = FALSE;

		
        // XXX Might need to validate $code as well
        if ($code != NULL)
		{
			$alert_code = ORM::factory('alert')
							->where('alert_code', $code)
                            ->delete_all();

            $this->template->content->unsubscribed = TRUE;
        }
	}
	
    /*
     * Retrieves Previously Cached Geonames Cities
     */
    private function _get_cities()
    {
        $cities = ORM::factory('city')->orderby('city', 'asc')->find_all();
        $city_select = array('' => Kohana::lang('ui_main.alerts_select_city'));
        foreach ($cities as $city) 
		{
            $city_select[$city->city_lon.",".$city->city_lat] = $city->city;
        }
        return $city_select;
    }


	/**
	 * Creates a confirmation code for use with email or sms verification 
	 */
	private function _mk_code()
	{
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$max = strlen($chars)-1;
		$code_length = 6;
		$code = NULL;

        // Generate unique codes only
		while(1)
		{
			for($i = 0; $i < $code_length; $i++) 
			{
				$code.=$chars[mt_rand(0, $max)];
			}

			$code_check = ORM::factory('alert')
							->where('alert_code', $code)->find();

			if (!$code_check->loaded)
				break;
		}

		return $code;
	}

	private function _send_mobile_alert($alert, $alert_mobile, $alert_lon, $alert_lat)
	{
		$alert_code = $this->_mk_code();
					
		$settings = ORM::factory('settings', 1);

		if (!$settings->loaded)
			return FALSE;

		// Get SMS Numbers
		if (!empty($settings->sms_no3)) 
		{
			$sms_from = $settings->sms_no3;
		}
		elseif (!empty($settings->sms_no2)) 
		{
			$sms_from = $settings->sms_no2;
		}
		elseif (!empty($settings->sms_no1)) 
		{
			$sms_from = $settings->sms_no1;
		}
		else
		{
			$sms_from = "000";// User needs to set up an SMS number
		}

		$sms = new Clickatell();
		$sms->api_id = $settings->clickatell_api;
		$sms->user = $settings->clickatell_username;
		$sms->password = $settings->clickatell_password;
		$sms->use_ssl = false;
		$sms->sms();
		$message = "Your alerts confirmation code
				is: ".$alert_code." This code is NOT case sensitive";
	
		if ($sms->send($alert_mobile, $sms_from, $message) == "OK")
		{
			$alert->alert_type = self::MOBILE_ALERT;
			$alert->alert_recipient = $alert_mobile;
			$alert->alert_code = $alert_code;
			$alert->alert_lon = $alert_lon;
			$alert->alert_lat = $alert_lat;
			$alert->save();

			return TRUE;
		}

		return FALSE;
	}

	private function _send_email_alert($alert, $alert_email, $alert_lon, $alert_lat)
	{
		$alert_code = $this->_mk_code();
		
		$config = kohana::config('alerts');
		$settings = kohana::config('settings');
		
		$to = $alert_email;
		$from = $config['alerts_email'];
		$subject = $settings['site_name']." "
					.Kohana::lang('alerts.verification_email_subject');
		$message = Kohana::lang('alerts.confirm_request')
					.url::site().'alerts/verify/'.$alert_code;

		if (email::send($to, $from, $subject, $message, TRUE) == 1)
		{
			$alert->alert_type = self::EMAIL_ALERT;
			$alert->alert_recipient = $alert_email;
			$alert->alert_code = $alert_code;
			$alert->alert_lon = $alert_lon;
			$alert->alert_lat = $alert_lat;
			$alert->save();
			
			return TRUE;
		}

		return FALSE;
	}	
}
