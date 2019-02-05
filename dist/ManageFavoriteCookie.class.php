<?php

/**
 * ManageFavoriteCookie
 * 
 * È un utility per Wordpress che permette di creare una lista di preferiti e salvarla come cookie di sessione.
 * 
 * @versione                        1.0.1
 * @data ultimo aggiornamento       05 Febbraio 2019
 * @data prima versione             20 Gennaio 2019
 * 
 * @autore                          Giorgio Suadoni
 * 
 */

// Disabilita le chiamate dirette a questa classe.
if (!defined('ABSPATH')) die;

class ManageFavoriteCookie {
	/**
	 * Il nome del cookie da salvare.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @var     string
	 */
	private $cookie_name = '';
	
	/**
	 * L'ora in cui scadrà il cookie.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @var     integer
	 */
	private $cookie_time = 0;
	
	/**
	 * Il percorso sul server in cui il cookie sarà disponibile.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @var     string
	 */
	private $cookie_path = '';
	
	/**
	 * L'URL completo della classa per caricare eventuali altri script.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @var     string
	 */
	private $url_class_dir;
	
	/**
	 * Chiave privata per la sicurezza cifrata del cookie.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @var     string
	 */
	private $secure_key = '';
	
	/**
	 * Il codice HTML da stampare per le funzioni di aggiunta/rimozione del preferito, quando richiesto.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @var     array
	 */
	private $output_html = array();
	
	/**
	 * Lista di parametri da stampare lato javascript.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @var     array
	 */
	private $js_config = array();
	
	/**
	 * Costruttore.
	 *
	 * @dalla v1.0
	 *
	 * @accesso pubblico
	 * @parametro string $cookie_name                Obbligatorio. Il nome del cookie da salvare in sessione.
	 * @parametro array  $text_or_icons_for_favorite Facoltativo.  Sovrascrive il risultato HTML per le funzioni di aggiunta/rimozione del preferito.
	 */
	public function __construct($cookie_name, $text_or_icons_for_favorite = array()) {
		add_action('wp_ajax_mfc_add_or_remove', array($this, '__ajax_add_or_remove_favorite'));
		add_action('wp_ajax_nopriv_mfc_add_or_remove', array($this, '__ajax_add_or_remove_favorite'));
		
		$path_arr = explode('/', __DIR__);
		$this->url_class_dir = home_url(implode('/', array_slice($path_arr, array_search('wp-content', $path_arr))));
		
		$this->cookie_name = sanitize_key($cookie_name);
		
		$this->cookie_time = (3600*24*31) * 12; // 12 mesi
		$this->cookie_path = COOKIEPATH;
		
		$this->output_html = array(
			'add' => __('Add to favorites', 'Manage Favorite'),
			'remove' => __('Remove from favorites', 'Manage Favorite'),
		);
		
		$secure_key = get_option('mfc_secure_key');
		
		if (empty($secure_key)) {
			$secure_key = base64_encode(openssl_random_pseudo_bytes(33));
			
			update_option('mfc_secure_key', $secure_key);
		}
		
		$this->secure_key = $secure_key;
		
		if (is_array($text_or_icons_for_favorite) && count($text_or_icons_for_favorite)) {
			if (isset($text_or_icons_for_favorite['add']) && isset($text_or_icons_for_favorite['remove']))
				$this->output_html = $text_or_icons_for_favorite;
		}
		
		$this->js_config = array(
			'admin_ajax' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('ajaxnonce'),
		);
		
		add_action('wp_enqueue_scripts', function() {
			wp_enqueue_script('manage-favorite', $this->url_class_dir .'/js/mfc.js', array('jquery'), filemtime(__DIR__ .'/js/mfc.js'), true);
			wp_localize_script('manage-favorite', 'mfc_config', $this->js_config);
		}, 20);
	}
	
	/**
	 * Richiama la funzione di aggiunta/rimozione del preferito.
	 *
	 * @dalla v1.0
	 *
	 * @accesso   pubblico
	 * @parametro integer $item_id Obbligatorio. L'ID da passare all'evento, per consentire alla funzione di capire se stampare l'icona di aggiunta o rimozione del preferito.
	 * @ritorno   string  Ritorna il codice HTML dell'icona da visualizzare.
	 */
	public function render($item_id = 0) {
		if (!$item_id)
			return '';
		
		$isFavorite = $this->_exist($item_id);
		
		if ($isFavorite) {
			$icon_favorite = '
			<a href="javascript:;" '. $this->_add_hook('remove', $item_id) .'">
				'. $this->output_html['add'] .'
			</a>
			';
		} else {
			$icon_favorite = '
			<a href="javascript:;" '. $this->_add_hook('add', $item_id) .'">
				'. $this->output_html['remove'] .'
			</a>
			';
		}
		
		return $icon_favorite;
	}
	
	/**
	 * Ritorna tutti gli ID salvati nel cookie come array.
	 *
	 * @dalla v1.0
	 *
	 * @accesso pubblico
	 * @ritorno array
	 */
	public function getIDsAsArray() {
		return $this->_get_all_ids();
	}
	
	/**************************************************************************************************
	 * METODI PRIVATI
	 **************************************************************************************************/
	
	/**
	 * Chiamata AJAX quando si fa click sull'evento di aggiunta/rimozione del preferito.
	 *
	 * @dalla v1.0
	 *
	 * @accesso pubblico (utilizzabile solo dalla classe)
	 * @ritorno json
	 */
	public function __ajax_add_or_remove_favorite() {
		$error = false;
		
		if (!wp_doing_ajax())
			$error = false;
		
		if (!wp_verify_nonce($_POST['nonce'], 'ajaxnonce'))
			$error = true;
		
		if (empty($_POST['item_id']) || !$_POST['item_id'])
			$error = true;
		
		if ($error) {
			$ajax_output = array(
				'success' => false,
			);
			
			wp_send_json($ajax_output);
		}
		
		if ($this->_exist($_POST['item_id'])) {
			$this->_remove($_POST['item_id']);
			
			$ajax_output = array(
				'remove' => true,
				'html' => $this->output_html['remove'],
			);
		} else {
			$this->_add($_POST['item_id']);
			
			$ajax_output = array(
				'remove' => false,
				'html' => $this->output_html['add'],
			);
		}
		
		/**
		 * Applica un filtro per altre operazioni ajax personalizzate.
		 *
		 * @dalla v1.0
		 *
		 * @parametro integer $item_id Obbligatorio. L'ID dell'item.
		 */
		do_action('mfc_custom_ajax', $_POST['item_id']);
		
		wp_send_json($ajax_output);
	}
	
	/**
	 * Ottiene tutti gli ID dei preferiti dal cookie.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @ritorno array|bool
	 */
	private function _get_favorite_by_cookie() {
		if (!isset($_COOKIE[$this->cookie_name]))
			return false;
		
		$encrypt_data = $_COOKIE[$this->cookie_name];
		
		if ($encrypt_data) {
			$encryption_key = base64_decode($this->secure_key);
			$favorite = json_decode(openssl_decrypt($encrypt_data, 'aes-256-cbc', $encryption_key));
			
			if (is_array($favorite) && count($favorite))
				return $favorite;
		}
		
		return false;
	}
	
	/**
	 * Salva la lista degli ID nel cookie.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @parametro array $favorite_ids Obbligatorio. Array di ID.
	 */
	private function _save($favorite_ids) {
		$encryption_key = base64_decode($this->secure_key);
		$cookies_save = openssl_encrypt(json_encode(array_unique($favorite_ids)), 'aes-256-cbc', $encryption_key);
		
		setcookie($this->cookie_name, $cookies_save, time() + $this->cookie_time, $this->cookie_path, $_SERVER['SERVER_NAME'], (is_ssl() ? true : false));
	}
	
	/**
	 * Aggiunge l'ID ai preferiti.
	 *
	 * @dalla v1.0
	 *
	 * @accesso   privato
	 * @parametro integer $item_id Obbligatorio. L'ID da aggiungere.
	 * @ritorno   bool
	 */
	private function _add($item_id) {
		$favorite = $this->_get_favorite_by_cookie();
		
		if ($favorite)
			$favorite[] = $item_id;
		else
			$favorite = array($item_id);
		
		$this->_save($favorite);
	}
	
	/**
	 * Rimuove l'ID dai preferiti.
	 *
	 * @dalla v1.0
	 *
	 * @accesso   privato
	 * @parametro integer $item_id Obbligatorio. L'ID da rimuovere.
	 * @ritorno   bool
	 */
	private function _remove($item_id) {
		$favorite = $this->_get_favorite_by_cookie();
		
		if ($favorite) {
			$keyFound = array_search($item_id, $favorite);
			
			if ($keyFound !== false)
				unset($favorite[$keyFound]);
			else
				return false;
			
			$favorite = array_values($favorite);
			
			$this->_save($favorite);
		}
		
		return false;
	}
	
	/**
	 * Controlla se l'ID è già inserito nei preferiti.
	 *
	 * @dalla v1.0
	 *
	 * @accesso   privato
	 * @parametro integer $item_id Obbligatorio. L'ID da controllare.
	 * @ritorno   bool
	 */
	private function _exist($item_id) {
		$favorite = $this->_get_favorite_by_cookie();
		
		if ($favorite && in_array($item_id, $favorite))
			return true;
		else
			return false;
	}
	
	/**
	 * Aggiunge l'evento click all'icona del preferito.
	 *
	 * @dalla v1.0
	 *
	 * @accesso   privato
	 * @parametro string  $type    Obbligatorio. Stabilisce la tipologia dell'evento.
	 * @parametro integer $item_id Obbligatorio. L'ID da associare all'evento.
	 * @ritorno   string
	 */
	private function _add_hook($type = '', $item_id = 0) {
		$accepted_type = array('remove', 'add');
		
		if (!in_array($type, $accepted_type) || !$item_id)
			return '';
		
		if ($type == 'remove')
			return 'data-mfc-hook="add-or-remove" class="mfc-remove-favorite" data-item-id="'. $item_id .'';
		elseif ($type == 'add')
			return 'data-mfc-hook="add-or-remove" class="mfc-add-favorite" data-item-id="'. $item_id .'';
	}
	
	/**
	 * Ritorna un array con tutti gli ID salvati nel cookie.
	 *
	 * @dalla v1.0
	 *
	 * @accesso privato
	 * @ritorno array
	 */
	private function _get_all_ids() {
		$default = array('');
		
		$favorite = $this->_get_favorite_by_cookie();
		
		if ($favorite)
			return $favorite;
		
		return $default;
	}
}