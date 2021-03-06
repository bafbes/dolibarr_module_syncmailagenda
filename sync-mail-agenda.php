<?php
chdir(__DIR__);
define('INC_FROM_CRON_SCRIPT', true);

require ('config.php');

ini_set('memory_limit','1024M');

dol_include_once('/syncmailagenda/class/syncmailagenda.class.php');
dol_include_once('/contact/class/contact.class.php');
dol_include_once('/societe/class/societe.class.php');
dol_include_once('/comm/action/class/actioncomm.class.php');

if (empty($conf->syncmailagenda->enabled))
	exit();


if (php_sapi_name() === 'cli')
	$entity = $argv[1];
else
	$entity = GETPOST('entity');

if (empty($entity))
	$entity=1;

$conf->entity = $entity;

print "Debut <br />";

$res = $db->query("SELECT DISTINCT ex.* FROM " . MAIN_DB_PREFIX . "user_extrafields ex
		LEFT JOIN " . MAIN_DB_PREFIX . "user u ON (u.rowid = ex.fk_object)
	WHERE ex.imap_connect IS NOT NULL AND u.statut=1  AND u.entity IN (0," . $entity . ")");

while ( $obj = $db->fetch_object($res) ) {
	print "Analyse de la boite de {$obj->imap_login}<br>";

	// Pour les messages reçus
	_sync_mailbox($obj->fk_object, $obj->imap_connect, $obj->imap_inbox_mailbox, $obj->imap_login, $obj->imap_password, false, true);

	// Pour les messages envoyés
	_sync_mailbox($obj->fk_object, $obj->imap_connect, $obj->imap_sent_mailbox, $obj->imap_login, $obj->imap_password, true, false);


}

print "Fin";
function _sync_mailbox($usertodo, $host, $mailbox, $login, $password, $labelForSendMessage = false, $inboxbox =true) {
	global $db, $conf;

	if(empty($mailbox)) return false;

	print "Tentative de connexion : " . $host . $mailbox . "\n<br />";
	// $mbox = imap_open($host, $login, $password);
	$mbox = imap_open($host . $mailbox, $login, $password);


	if ($mbox === false) {
		print "Connexion impossible  $host, $mailbox, $login<br />";
		print 'ERROR IMAP : '.var_export(imap_errors(),true);
		return false;
	} else {
		print "Connect ok $host, $mailbox, $login<br />";
	}

	$info = imap_check($mbox);
	if ($info === false) {
		print "Erreur imap_check<br />";
		return false;
	}
	// $typeBoite = _getTypeBoiteMessage($host);
	// var_dump($info);
	$last_message = $info->Nmsgs;
	$nb_mail_to_parse = (empty($conf->global->IMAP_MAX_PARSE_MAIL) || $conf->global->IMAP_MAX_PARSE_MAIL > $info->Recent) ? $info->Recent : $conf->global->IMAP_MAX_PARSE_MAIL;
	if ($nb_mail_to_parse <= 1)
		$nb_mail_to_parse = 10;

	$point_to_start = $last_message - $nb_mail_to_parse + 1;

	if ($point_to_start < 1)
		$point_to_start = 1;

	$search = $point_to_start . ":" . $last_message;
	print "Recherche : " . $search . "<br />";

	try {

		$result = imap_fetch_overview($mbox, $search, 0);

	foreach ( $result as $overview ) {

		// var_dump($overview);

		$msg_number = $overview->msgno;

		$from = sanitize_mail($overview->from);

		if (! empty($from)) {

			$to = sanitize_mail($overview->to);

			$estUnIdDuneSociete = false;

			$id_contact = $inboxbox ? getContactFromMail($from) : getContactFromMail($to);

			if (! $id_contact) {
				$id_contact = $inboxbox ? getSocFromMail($from) : getSocFromMail($to);
				if($id_contact>0) $estUnIdDuneSociete = true;
			}

			if ($id_contact || ! empty($conf->global->IMAP_SYNC_MAIL_FROM_UNKNOWN)) {

				$contact = new Contact($db);
				$societe = new Societe($db);

				if (! $estUnIdDuneSociete) {
					$contact->fetch($id_contact);
				}

				if ($estUnIdDuneSociete) {
					$societe->fetch($id_contact);
				} else {
					$societe->fetch($contact->socid);
				}

				$overview->message_id = trim($overview->message_id);

				if(!empty($conf->global->SYNCMAILAGENDA_DO_NOT_UPDATE_MSG) && checkEventExist($overview->message_id)) {
					
					continue;
					
				}
//var_dump($mbox);
//echo '1'.$msg_number;
//continue;
				try {
					list ( $body, $htmlbody, $attachements ) = getmsg($mbox, $msg_number);
			
//echo '2';
				$body = nl2br($body);
				
				$messageid = ! empty($overview->message_id) ? $overview->message_id : md5($body . $htmlbody . serialize($attachements));
				// var_dump($to,$overview->subject,$body, $messageid);
				print "Ajout evenement(" . htmlentities($messageid) . ") $from de la société " . $societe->nom;

				date_default_timezone_set('Europe/Paris');
				$t_event = strtotime($overview->date);
				// $date = DateTime::createFromFormat($overview->date);
				// $t_event = $date->getTimestamp();

				if (empty($conf->global->SYNCMAILAGENDA_ONLY_MAIL_OBJECT)) {
					addEvent($usertodo, $from, $societe, $contact, imap_utf8($overview->subject), $body, $htmlbody, $attachements, $t_event, $messageid, $mailbox, $to, $labelForSendMessage);
				} elseif (! empty($id_contact)) {
					addMail($usertodo, $from, $societe, $contact, imap_utf8($overview->subject), $body, $htmlbody, $attachements, $t_event, $messageid, $mailbox, $to, $labelForSendMessage);
				}


				}
				catch(Exception $e) {
			                var_dump($e);
        			}

				print "<br>-----------<br>";
			} else {
				print "From : $from non reconnu, To : $to<br>";
			}

			flush();
		}
	}

	}
	catch(Exception $e) {
		var_dump($e);
	}

	imap_close($mbox);
}

/**
 * Permet de déterminer si un message provient de la boite de massage reçus (INBOX) ou bien de le boite de messages envoyés (SENT)
 *
 * @param string $chaineConnexion : {imap.gmail.com:993/imap/ssl}...
 * @return string $type : 'INBOX' ou 'Sent Mail'
 */
function _getTypeBoiteMessage($chaine) {
	if (strpos($chaine, 'INBOX') !== false) {
		return 'INBOX';
	} else if (strpos($chaine, 'Sent Mail') !== false) {
		return 'Sent Mail';
	}
}
function getContactFromMail($mail) {
	global $db, $conf;

	$res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "socpeople WHERE email='" . $mail . "'");
	if ($obj = $db->fetch_object($res)) {
		return $obj->rowid;
	}

	return 0;
}
function getSocFromMail($mail) {
	global $db, $conf;

	$res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE email='" . $mail . "'");
	if ($obj = $db->fetch_object($res)) {
		return $obj->rowid;
	}

	return 0;
}
function getUserFromMail($mail) {
	global $db, $conf;

	$res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE email='" . $mail . "'");
	if ($obj = $db->fetch_object($res)) {
		return $obj->rowid;
	}

	return 0;
}
function sanitize_mail($from) {
	$matches = array ();
	$pattern = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}\b/i';
	preg_match_all($pattern, $from, $matches);

	if (isset($matches[0][0]))
		return $matches[0][0];
	else
		return '';
}
function checkEventExist($message_id) {
	global $db;
	
	$m = new SyncMailAgenda($db);

	if ($m->fetchBy($message_id, 'messageid')) {
		print "Le mail existe déjà ".htmlentities($message_id)."<br />";
		// $event->fetch($obj->id);
		return true;
	}
	else{
		return false;	
	}	
	
}

function addMail($usertodo, $from, $societe, $contact, $subject, $body, $htmlbody, $TAttachement, $time_receip, $message_id, $typeBoite, $mailto, $labelForSendMessage = false) {
    global $db, $conf,$user;

	$m = new SyncMailAgenda($db);

	if ($m->fetchBy($message_id, 'messageid')) {
		print "Le mail existe déjà $message_id<br>";
		// $event->fetch($obj->id);
		// return false;
	}

	$m->title = $subject;
	$m->body = $body;
	$m->messageid = $message_id;
	$m->fk_soc = $societe->id;
	$m->fk_contact = $contact->id;
	$m->fk_user = $usertodo;

	$m->mto = $mailto;
	$m->mfrom = $from;

	$m->create($user);

	$upload_dir = $conf->syncmailagenda->dir_output . '/' . $m->getId();

	@mkdir($upload_dir, 0777, true);
	if (! empty($htmlbody))
		file_put_contents($upload_dir . '/mail.html', $htmlbody);

	foreach ( $TAttachement as $filename => $att ) {

		file_put_contents($upload_dir . '/' . $filename, $att);
	}
	print "Ajout du mail $message_id<br>";
}
function addEvent($usertodo, $from, $societe, $contact, $subject, $body, $htmlbody, $TAttachement, $time_receip, $message_id, $typeBoite, $mailto, $labelForSendMessage = false) {
	global $db, $conf;

	// var_dump($time_receip, date('Y-m-d H:i', $time_receip));

	$event = new ActionComm($db);

	$db->query("SELECT id FROM " . MAIN_DB_PREFIX . "actioncomm WHERE ref_ext = '" . $message_id . "' AND entity = " . $conf->entity);

	$contact_label = $contact->firstname . ' ' . $contact->lastname;

	if ($obj = $db->fetch_object($res)) {
		// existe déjà
		print "L'événement existe déjà {$obj->id}<br>";
		// $event->fetch($obj->id);
		return false;
	} else {
		// n'existe pas encore

		$event->ref_ext = $message_id;
		/**
		 * Si le mail du contact n'existe pas dans la liste des socpeople, on n'a ni le mail du contact, ni le mail du client,
		 * étant donné que l'on cherche dans la liste des soceople uniquement.
		 * Mais il se peut que le mail soit un mail direct d'une société,
		 * on veut donc quand même récupérer le nom de la société, si elle existe.
		 */
		if (empty($contact_label) || $contact_label == ' ') {
			$id_soc = getSocFromMail($from);
			// echo "*** ".$id_soc." ***<br />";
			if ($id_soc > 0) {
				$societe = new Societe($db);
				$societe->fetch($id_soc);
			}
		}

		if (! $labelForSendMessage) {

			// $event->label = "Société ".($societe ? $societe : "(Inconnue)" ).' : Mail reçu de '.($contact == " " || empty($contact) ? "(Inconnu)" : $contact).' : '.$subject ;
			$event->label = "Société " . ($societe->nom ? $societe->nom : "(inconnue)") . ' : Mail reçu de ' . ($contact_label == " " || empty($contact_label) ? $from : $contact_label) . ' - Sujet : ' . $subject;

			// } else if($typeBoite == 'Sent Mail') {
		} else {

			// $event->label = "Mail envoyé à ".$overview->to." : ".$subject;
			$event->label = "Mail envoyé à " . ($contact_label == " " || empty($contact_label) ? $mailto : $contact_label) . ", Société " . ($societe->nom ? $societe->nom : "(inconnue)") . " - Sujet : " . $subject;
		}

		$event->note = "Contenu du mail : <br /><br />" . $body;
		$event->datep = $time_receip;

		$event->type_code = 1;
		$event->type = 1;
		$event->location = '';

		$event->type_id = 50;

		$event->percentage = 100;

		$event->usertodo->id = $usertodo;

		if ($societe->id > 0) {
			$event->societe->id = $societe->id;
		}

		if ($contact->id > 0) {
			$event->contact->id = $contact->id;
		}
		$user = new User($db);
		$user->fetch($usertodo);

		// print_r($event);
		if ($event->add($user, 1) < 0) {
			print $event->error;
		} else {

			$upload_dir = $conf->agenda->dir_output . '/' . dol_sanitizeFileName($event->id);
			$modulepart = 'contract';

			@mkdir($upload_dir);
			file_put_contents($upload_dir . '/mail.html', $htmlbody);

			foreach ( $TAttachement as $filename => $att ) {

				file_put_contents($upload_dir . '/' . $filename, $att);
			}
			print "Ajout de l'événement {$event->id}<br>";
			// Dolidaube ?
			$db->query("UPDATE " . MAIN_DB_PREFIX . "actioncomm SET ref_ext='" . $message_id . "' WHERE id=" . $event->id);
		}

		return true;
	}
}
function getmsg($mbox, $mid) {
	// input $mbox = IMAP stream, $mid = message id
	// output all the following:
	$htmlmsg = $plainmsg = $charset = '';
	$attachments = array ();
	// HEADER
	$h = imap_header($mbox, $mid);
	// add code here to get date, from, to, cc, subject...
	// BODY
	$s = imap_fetchstructure($mbox, $mid);
	if (! $s->parts) { // simple
		list ( $plain, $html, $att ) = getpart($mbox, $mid, $s, 0); // pass 0 as part-number
		$plainmsg .= $plain;
		$htmlmsg .= $html;
		$attachments = array_merge($attachments, $att);
	} else { // multipart: cycle through each part
		foreach ( $s->parts as $partno0 => $p ) {
			list ( $plain, $html, $att ) = getpart($mbox, $mid, $p, $partno0 + 1);
			$plainmsg .= $plain;
			$htmlmsg .= $html;
			$attachments = array_merge($attachments, $att);
		}
	}
	return array (
			$plainmsg,
			$htmlmsg,
			$attachments
	);
}
function getpart($mbox, $mid, $p, $partno) {
	// $partno = '1', '2', '2.1', '2.1.3', etc for multipart, 0 if simple
	$attachments = array ();
	// DECODE DATA

	$data = ($partno) ? imap_fetchbody($mbox, $mid, $partno) : // multipart
imap_body($mbox, $mid); // simple
	                       // Any part may be encoded, even plain text messages, so check everything.
	if ($p->encoding == 4)
		$data = quoted_printable_decode($data);
	elseif ($p->encoding == 3)
		$data = base64_decode($data);
		// PARAMETERS
		// get all parameters, like charset, filenames of attachments, etc.
	$params = array ();
	if ($p->parameters)
		foreach ( $p->parameters as $x )
			$params[strtolower($x->attribute)] = $x->value;
	if ($p->dparameters)
		foreach ( $p->dparameters as $x )
			$params[strtolower($x->attribute)] = $x->value;

		// ATTACHMENT
		// Any part with a filename is an attachment,
		// so an attached text file (type 0) is not mistaken as the message.
	if ($params['filename'] || $params['name']) {
		// filename may be given as 'Filename' or 'Name' or both
		$filename = ($params['filename']) ? $params['filename'] : $params['name'];
		// filename may be encoded, so see imap_mime_header_decode()
		$attachments[$filename] = $data; // this is a problem if two files have same name
	}

	// TEXT
	if ($p->type == 0 && $data) {
		// Messages may be split in different parts because of inline attachments,
		// so append parts together with blank row.
		if (strtolower($p->subtype) == 'plain')
			$plainmsg .= force_utf8(trim($data)) . "\n\n";
		else
			$htmlmsg .= force_utf8($data) . "<br><br>";
		$charset = $params['charset']; // assume all parts are same charset
	}

	// EMBEDDED MESSAGE
	// Many bounce notifications embed the original message as type 2,
	// but AOL uses type 1 (multipart), which is not handled here.
	// There are no PHP functions to parse embedded messages,
	// so this just appends the raw source to the main message.
	elseif ($p->type == 2 && $data) {
		$plainmsg .= $data . "\n\n";
	}
	// SUBPART RECURSION
	if ($p->parts) {
		foreach ( $p->parts as $partno0 => $p2 ) {
			list ( $sub_plainmsg, $sub_htmlmsg, $sub_attachments ) = getpart($mbox, $mid, $p2, $partno . '.' . ($partno0 + 1));
			$plainmsg .= $sub_plainmsg;
			$htmlmsg .= $sub_htmlmsg;
			$attachments = array_merge($attachments, $sub_attachments);
		}

	}

	// if($plainmsg=='' && $htmlmsg!='')$plainmsg=$htmlmsg;

	return array (
			$plainmsg,
			$htmlmsg,
			$attachments
	);
}


/**
 * Check for UTF-8 compatibility
 *
 * Regex from Martin Dürst
 * @source http://www.w3.org/International/questions/qa-forms-utf-8.en.php
 * @param string $str String to check
 * @return boolean
 */
function is_utf8($str)
{
	$TString = str_split ( $str, 512 );
	
	foreach($TString as $str) {

	$res = preg_match("/^(
			 [\x09\x0A\x0D\x20-\x7E]            # ASCII
		   | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
		   |  \xE0[\xA0-\xBF][\x80-\xBF]        # excluding overlongs
		   | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
		   |  \xED[\x80-\x9F][\x80-\xBF]        # excluding surrogates
		   |  \xF0[\x90-\xBF][\x80-\xBF]{2}     # planes 1-3
		   | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
		   |  \xF4[\x80-\x8F][\x80-\xBF]{2}     # plane 16
		  )*$/x", $str
			);

		if($res)return true;
	}
	return false;
}
/**
 * Try to convert a string to UTF-8.
 *
 * @author Thomas Scholz <http://toscho.de>
 * @param string $str String to encode
 * @param string $inputEnc Maybe the source encoding.
 *               Set to NULL if you are not sure. iconv() will fail then.
 * @return string
 */
function force_utf8($str, $inputEnc = 'WINDOWS-1252')
{
	if (is_utf8($str)) // Nothing to do.
		return $str;

	if (strtoupper($inputEnc) === 'ISO-8859-1')
		return utf8_encode($str);

	if (function_exists('mb_convert_encoding'))
		return mb_convert_encoding($str, 'UTF-8', $inputEnc);

	if (function_exists('iconv'))
		return iconv($inputEnc, 'UTF-8', $str);
	trigger_error(
			'Cannot convert string to UTF-8 in file '
		.__FILE__.', line '.__LINE__.'!', E_USER_WARNING
	);

	return $str;
}
