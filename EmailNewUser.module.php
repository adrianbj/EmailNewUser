<?php

/**
 * Processwire module to email new user their account details.
 * by Adrian Jones
 *
 * Copyright (C) 2020 by Adrian Jones
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 *
 */

class EmailNewUser extends WireData implements Module, ConfigurableModule {

    /**
     * Basic information about module
     */
    public static function getModuleInfo() {
        return array(
            'title' => 'Email New User',
            'summary' => 'Email new user their account details, and optionally automatically generate a password for them.',
            'author' => 'Adrian Jones',
            'href' => 'http://modules.processwire.com/modules/email-new-user/',
            'version' => '1.1.13',
            'autoload' => "template=admin",
            'singular' => true,
            'icon' => 'envelope-o',
            'requires' => 'ProcessWire>=2.5.14',
        );
    }

    /**
     * Data as used by the get/set functions
     *
     */
    protected $data = array();
    protected $newPass = "";

   /**
     * Default configuration for module
     *
     */
    static public function getDefaultData() {
            return array(
                "automaticEmailSend" => 1,
                "fromEmail" => wire('config')->adminEmail,
                "fromName" => "",
                "replyToEmail" => "",
                "replyToName" => "",
                "bccEmail" => "",
                "addParam" => "",
                "generatePassword" => "",
                "subject" => "",
                "body" => ""
            );
    }

    /**
     * Populate the default config data
     *
     */
    public function __construct() {
        foreach(self::getDefaultData() as $key => $value) {
            $this->$key = $value;
        }

        if($this->wire('languages') && $this->wire('input')->language) {
            $userLanguage = $this->wire('languages')->get($this->wire('input')->language);
            $this->lang = $userLanguage->isDefault() ? '' : "__$userLanguage->id";
        }
        else {
            $this->lang = '';
        }

    }

    public function init() {
        $this->wire()->addHookBefore('Pages::saveReady', $this, 'prepareNewUserEmail');
    }

    public function ready() {
        if($this->data['generatePassword']) {
            $this->wire()->addHookBefore('InputfieldPassword::render', $this, 'populatePassword');
        }
        if($this->wire('page')->process == "ProcessUser") $this->wire()->addHookAfter('ProcessPageEdit::buildFormContent', $this, 'addEmailFields');
        $this->wire()->addHookAfter('Password::setPass', $this, 'getPassword');
    }

    protected function populatePassword(HookEvent $event) {
        $process = $this->wire('process');
        if($process instanceof WirePageEditor) {
            $userpage = $process->getPage();
            if($userpage->is(Page::statusUnpublished)) {
                $inputfield = $event->object;
                $minLength = $inputfield->minlength > 12 ? $inputfield->minlength : 12;
                $newPass = $this->passwordGenerator($minLength, false, $this->buildPasswordCharacterSets($inputfield));
                $inputfield->set('value', $newPass);
                $inputfield->showPass = true;
                $inputfield->notes = 'NB: Because you chose to automatically generate the password for new users, this has been populated automatically as: ' . $newPass;
            }
        }
    }

    protected function addEmailFields(HookEvent $event) {

        $form = $event->return;
        $page = $event->object->getPage();

        $notes = '';
        $forcePasswordChangeText = '';
        $checked = '';
        if($this->wire('modules')->isInstalled("PasswordForceChange")) $forcePasswordChangeText = __("\n\nIf you are relying on the automatically generated password, and/or you are including the password in the email, you should propbably check 'Force password change on next login'.");
        if(!$page->is(Page::statusUnpublished)) {
            $sendLabel = __("Re-send welcome message");
            if($this->data['generatePassword']) $notes = __("WARNING: This will overwrite the user's existing password because you have the Generate Password option checked.\nYou can manually enter a new password to overwrite the automatically generated one.") . $forcePasswordChangeText;
        }
        else {
            if($this->data['automaticEmailSend']) $checked = 'checked';
            $sendLabel = __("Send welcome message");
            if($this->data['generatePassword']) $notes = __("The system will generate an automatic password for the new user.") . $forcePasswordChangeText;
        }

        $f = $this->wire('modules')->get('InputfieldCheckbox');
        $f->attr('name', 'sendEmail');
        $f->notes = $notes;
        $f->label = $sendLabel;
        $f->showIf = "email!='', roles.count>1";
        $f->attr('checked', $checked);
        $f->collapsed = Inputfield::collapsedBlank;
        $form->append($f);

        $f = $this->wire('modules')->get('InputfieldCKEditor');
        $f->attr('name', 'emailMessage');
        $f->label = "Email Message";
        $f->showIf = "email!='', sendEmail=1, roles.count>1";
        $f->value = $this->data['body'];
        $f->useLanguages = true;
        if($this->wire('languages')) {
            foreach($this->wire('languages') as $language) {
                if($language->isDefault()) continue;
                if(isset($this->data['body__'.$language])) $f->set("value$language", $this->data['body__'.$language]);
            }
        }
        $f->description = __("Body text for the email. Use this to overwrite the default message from the module config settings.");
        $f->notes = __("Use: {name} and {pass}, or any other fields from the user template, eg. {first_name} in the text where you want them to appear in the email.\nPlease note that {adminUrl} and {fromEmail} are two special codes and not fields from the user template. These will return http://".$this->wire('config')->httpHost.$this->wire('config')->urls->admin." and {$this->data['fromEmail']}, respectively.");
        $form->append($f);

    }

    /**
     * Get the plain text version of the entered password (manual or automatically generated)
     *
     */
    protected function getPassword(HookEvent $event) {
        $this->newPass = $event->arguments[0];
    }


    protected function prepareNewUserEmail(HookEvent $event) {

        $page = $event->arguments(0);

        if($this->wire('page')->process == 'ProcessUser' && !$this->wire('input')->sendEmail) return; // exit if in admin and sendEmail checkbox was not selected
        if($this->wire('page')->process != 'ProcessUser' && !$this->data['automaticEmailSend'] && !$page->sendEmail) return; // exit if using API and automatic email send not checked
        if($this->wire('page')->process == 'ProcessProfile') return; // exit if editing profile
        if(!in_array($page->template->id, $this->wire('config')->userTemplateIDs)) return; // return now if not a user template

        if($this->wire('modules')->isInstalled("PasswordForceChange") && $this->wire('input')->force_passwd_change && !$page->hasPermission("profile-edit")) {
            $this->error($this->_("No email was sent to the user because of Force Password Change errors. Correct the error and then check the 'Re-send welcome message' option."));
            return;
        }

        // if not using re-send option and the username already exists, exit now
        // to get to this point with sendEmail not set, there must be an API call (template or other module)
        if(!$this->wire('input')->sendEmail && $this->wire('users')->get($page->name)->id) {
            return;
        }

        if($this->data['generatePassword'] && $this->newPass == '') {
            $minLength = $this->wire('fields')->get('pass')->minlength > 12 ? $this->wire('fields')->get('pass')->minlength : 12;
            $newPass = $this->passwordGenerator($minLength, false, $this->buildPasswordCharacterSets($this->wire('fields')->get('pass')));
            $page->pass = $newPass;
            $this->wire()->message($this->_("The automatically generated password for {$page->name} is $newPass"));
        }
        else{ // manually entered only, or manually entered to override automatically generated password
            $newPass = $this->newPass;
        }

        $this->wire()->message($this->_("The automatically generated password for {$page->name} is $newPass"));

        if($page->emailMessage) {
            $htmlBody = $page->emailMessage;
        }
        elseif($this->wire('input')->emailMessage) {
            $htmlBody = $this->input->{'emailMessage'.$this->lang};
        }
        else {
            $htmlBody = $this->data['body'.$this->lang];
        }
        $htmlBody = $this->parseBody($this->wire('sanitizer')->purify($htmlBody), $this->data['fromEmail'], $page, $newPass);

        if($page->pass == '' || $page->email == '') {
            $this->wire()->error($this->_("No email was sent to the new user because either their email address or password was not set."));
        }
        else{
            $sent = $this->sendNewUserEmail($page->email, $this->data['fromEmail'], $this->data['fromName'], $this->data['subject'.$this->lang], $htmlBody, $this->data['replyToEmail'], $this->data['replyToName']);
            if($sent) {
                $this->wire()->message($this->_("{$page->name} was successfully sent a welcome email."));
            }
            else {
                $this->wire()->error($this->_("No email was sent to the new user because of an unknown problem. Please try the 'Re-send Welcome Message' option."));
            }
        }

    }


    private function sendNewUserEmail($to, $fromEmail, $fromName, $subject, $htmlBody, $replyToEmail=false, $replyToName=false) {
        $mailer = $this->wire('mail') ? $this->wire('mail')->new() : wireMail();
        $mailer->to($to);
        if($this->data['bccEmail'] != '') {
            foreach(explode(',', $this->data['bccEmail']) as $bccEmail) {
                $mailer->to(trim($bccEmail));
            }
        }
        if($this->data['addParam'] != '') {
            $mailer->param(trim($this->data['addParam']));
        }
        $mailer->from($fromEmail);
        $mailer->fromName($fromName);
        if($replyToEmail) {
            $mailer->replyTo($replyToEmail, $replyToName);
        }
        $mailer->subject($subject);
        $mailer->body($this->parseTextBody($htmlBody));
        $mailer->bodyHTML($htmlBody);
        $sent = $mailer->send();

        return $sent;
    }


    public function ___parseBody($htmlBody, $fromEmail, $page, $newPass) {
        if (preg_match_all('/{([^}]*)}/', $htmlBody, $matches)) {
            foreach ($matches[0] as $match) {
                $field = str_replace(array('{','}'), '', $match);

                if($field == "pass") {
                    $replacement = $newPass;
                }
                elseif($field == "adminUrl") {
                    $replacement = $this->wire('config')->urls->httpAdmin;
                }
                elseif($field == "fromEmail") {
                    $replacement = $fromEmail;
                }
                elseif($page->$field) {
                    $replacement = $page->$field;
                }
                else {
                    // if no replacement available, add a non-breaking space
                    // this prevents removal of line break when converting to plain text version
                    $replacement = '&nbsp;';
                }

                $htmlBody = str_replace($match, $replacement, $htmlBody);
            }
        }
        return $htmlBody;
    }


    private function parseTextBody($str) {
        $str = $this->wire('sanitizer')->textarea($str);
        $str = $this->text_target($str);
        $str = $this->remove_html_comments($str);
        $str = preg_replace('/(<|>)\1{2}/is', '', $str);
        $str = preg_replace(
            array(// remove invisible content
                '@<head[^>]*?>.*?</head>@siu',
                '@<style[^>]*?>.*?</style>@siu',
                '@<script[^>]*?.*?</script>@siu',
                '@<noscript[^>]*?.*?</noscript>@siu',
                ),
            "", // replace above with nothing
            $str );
        $str = preg_replace('#(<br */?>\s*)+#i', '<br />', $str);
        $str = strip_tags($str);
        $str = $this->replaceWhitespace($str);
        return $str;
    }


    private function remove_html_comments($str) {
        return preg_replace('/<!--(.|\s)*?-->/', '', $str);
    }


    // convert links to be url in parentheses after linked text
    private function text_target($str) {
        return preg_replace('/<a href="(.*?)">(.*?)<\\/a>/i', '$2 ($1)', str_replace(' target="_blank"','',$str));
    }

    private function replaceWhitespace($str) {
        $result = $str;
        foreach (array(
        "  ", " \t",  " \r",  " \n",
        "\t\t", "\t ", "\t\r", "\t\n",
        "\r\r", "\r ", "\r\t", "\r\n",
        "\n\n", "\n ", "\n\t", "\n\r",
        ) as $replacement) {
        $result = str_replace($replacement, $replacement[0], $result);
        }
        return $str !== $result ? $this->replaceWhitespace($result) : $result;
    }


    private function buildPasswordCharacterSets($inputfield) {
        $characterSets = 'lud'; // without these, the password is often validated as too common
        if($inputfield->requirements) {
            foreach($inputfield->requirements as $requirement) {
                if($requirement === 'other') {
                    $characterSets .= 's';
                }
            }
        }
        return $characterSets;
    }


    // https://gist.github.com/tylerhall/521810
    // Generates a strong password of N length containing at least one lower case letter,
    // one uppercase letter, one digit, and one special character. The remaining characters
    // in the password are chosen at random from those four sets.
    //
    // The available characters in each set are user friendly - there are no ambiguous
    // characters such as i, l, 1, o, 0, etc. This, coupled with the $add_dashes option,
    // makes it much easier for users to manually type or speak their passwords.
    //
    // Note: the $add_dashes option will increase the length of the password by
    // floor(sqrt(N)) characters.

    private function passwordGenerator($length = 9, $add_dashes = false, $available_sets = 'luds') {

        $sets = array();
        if(strpos($available_sets, 'l') !== false)
            $sets[] = 'abcdefghjkmnpqrstuvwxyz';
        if(strpos($available_sets, 'u') !== false)
            $sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        if(strpos($available_sets, 'd') !== false)
            $sets[] = '23456789';
        if(strpos($available_sets, 's') !== false)
            $sets[] = '!@#$%&*?';

        $all = '';
        $password = '';
        foreach($sets as $set) {
            $password .= $set[array_rand(str_split($set))];
            $all .= $set;
        }

        $all = str_split($all);
        for($i = 0; $i < $length - count($sets); $i++)
            $password .= $all[array_rand($all)];

        $password = str_shuffle($password);

        if(!$add_dashes)
            return $password;

        $dash_len = floor(sqrt($length));
        $dash_str = '';
        while(strlen($password) > $dash_len) {
            $dash_str .= substr($password, 0, $dash_len) . '-';
            $password = substr($password, $dash_len);
        }
        $dash_str .= $password;
        return $dash_str;
    }

    /**
     * Return an InputfieldsWrapper of Inputfields used to configure the class
     *
     * @param array $data Array of config values indexed by field name
     * @return InputfieldsWrapper
     *
     */
    public function getModuleConfigInputfields(array $data) {

        $data = array_merge(self::getDefaultData(), $data);

        // send test email if requested
        if ($this->wire('input')->post->test) {

            $sent = $this->sendNewUserEmail($this->wire('user')->email, $data['fromEmail'], $data['fromName'], $data['subject'.$this->lang], $this->parseBody($data['body'.$this->lang], $data['fromEmail'], $this->wire('user'), 'password'), $data['replyToEmail'], $data['replyToName']);
            if($sent) {
                $this->wire()->message($this->_("Test email was sent successfully."));
            }
            else {
                $this->wire()->error($this->_("Test email was NOT sent because of an unknown problem."));
            }

        }


        $wrapper = new InputfieldWrapper();

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->attr('name', 'automaticEmailSend');
        $f->label = __('Automatic Email Send');
        $f->description = __('If checked, the "Send Welcome Message" option will be automatically checked for each new user when they are created.');
        $f->notes = __('This also affects API additions of new users. If you want this unchecked and want to force the email to be sent for a specific new user, use: $newuser->sendEmail = true;');
        $f->attr('checked', $data['automaticEmailSend'] ? 'checked' : '' );
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldEmail");
        $f->attr('name', 'fromEmail');
        $f->label = __('From Email Address');
        $f->description = __('Email address that the email will come from.');
        $f->notes = __("If this field is blank, the email will not be sent.");
        $f->columnWidth = 50;
        $f->value = $data['fromEmail'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldText");
        $f->attr('name', 'fromName');
        $f->label = __('From Name');
        $f->description = __('Name that the email will come from.');
        $f->columnWidth = 50;
        $f->value = $data['fromName'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldEmail");
        $f->attr('name', 'replyToEmail');
        $f->label = __('Reply To Email Address');
        $f->columnWidth = 50;
        $f->value = $data['replyToEmail'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldText");
        $f->attr('name', 'replyToName');
        $f->label = __('Reply To Name');
        $f->columnWidth = 50;
        $f->value = $data['replyToName'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldText");
        $f->attr('name', 'bccEmail');
        $f->label = __('Notify Other Users');
        $f->description = __('Email addresses to also send the email to. Useful if you want to notify admins of new users.');
        $f->notes = __('Provide a comma-separated list of addresses.');
        $f->value = $data['bccEmail'];
        $f->collapsed = Inputfield::collapsedBlank;
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldText");
        $f->attr('name', 'addParam');
        $f->label = __('Additional Param');
        $f->description = __('Add an additional param for WireMail');
        $f->notes = __('e.g. -f allowdemailsender@'.$this->wire('config')->httpHost);
        $f->value = $data['addParam'];
        $f->collapsed = Inputfield::collapsedBlank;
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldText");
        $f->attr('name', 'subject');
        $f->label = __('Email Subject');
        $f->description = __('Subject text for the email');
        $f->value = $data['subject'];
        $f->useLanguages = true;
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldCKEditor");
        $f->attr('name', 'body');
        $f->label = __('Email Body');
        $f->description = __('Body text for the email');
        $f->notes = __("Use: {name} and {pass}, or any other fields from the user template, eg. {first_name} in the text where you want them to appear in the email. eg:\n---------------------------------------------------------------------------------------\n\nWelcome {first_name} {last_name}\n\nPlease login in at: {adminUrl}\n\nUsername: {name}\nPassword: {pass}\n\nIf you have any questions, please email us at: {fromEmail}\n\n---------------------------------------------------------------------------------------\nPlease note that {adminUrl} and {fromEmail} are two special codes and not fields from the user template. These will return http://".$this->wire('config')->httpHost.$this->wire('config')->urls->admin." and {$data['fromEmail']}, respectively.\n\n");
        $f->value = $data['body'];
        $f->useLanguages = true;
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->attr('name', 'generatePassword');
        $f->label = __('Generate Password');
        $f->description = __('Whether to automatically generate a password for the user.');
        $f->attr('checked', $data['generatePassword'] ? 'checked' : '' );
        $f->notes = __("An automatically generated password will be assigned to the user.\nIf you have included {pass} in the email body then it will be emailed to them. If not, the password will be displayed in the message bar at the top of the page after you save the user - be sure to note it somewhere.");
        $wrapper->add($f);


        // test send option
        if($this->wire('user')->email != '') {
            $f = $this->wire('modules')->get("InputfieldCheckbox");
        }
        else {
            $f = $this->wire('modules')->get("InputfieldMarkup");
        }
        $f->name = "test";
        $f->label = __("Test Send");
        if($this->wire('user')->email != '') {
            $f->description = __('On settings submit, a test email will be sent to ' . $this->wire('user')->email . ' with your account details.');
        }
        else {
            $f->description = __('There is no email address associated with your user account. Please add one to your profile to be able to send a test message.');
        }
        $f->collapsed = Inputfield::collapsedBlank;
        $wrapper->add($f);

        return $wrapper;
    }

}
