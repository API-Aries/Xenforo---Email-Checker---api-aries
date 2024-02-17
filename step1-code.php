<?php

namespace XF\Service\User;

use function intval;

class Registration extends \XF\Service\AbstractService
{
    use \XF\Service\ValidateAndSavableTrait;

    /**
     * @var \XF\Entity\User
     */
    protected $user;

    protected $fieldMap = [
        'username' => 'username',
        'email' => 'email',
        'timezone' => 'timezone',
        'location' => 'Profile.location',
    ];

    protected $logIp = true;

    protected $avatarUrl = null;

    protected $preRegActionKey = null;
    protected $preRegContent = null;

    protected $skipEmailConfirm = false;

    protected function setup()
    {
        $this->user = $this->app->repository('XF:User')->setupBaseUser();
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setMapped(array $input)
    {
        foreach ($this->fieldMap AS $inputKey => $entityKey)
        {
            if (!isset($input[$inputKey]))
            {
                continue;
            }

            $value = $input[$inputKey];
            if (strpos($entityKey, '.'))
            {
                list($relation, $relationKey) = explode('.', $entityKey, 2);
                $this->user->{$relation}->{$relationKey} = $value;
            }
            else
            {
                $this->user->{$entityKey} = $value;
            }
        }
    }

    public function setPassword($password, $passwordConfirm = '', $doPasswordConfirmation = true)
    {
        // Existing setPassword method...
    }

 // paste right under here

  ////////////////////////////////////////////////

    public function setEmail($email)
    {
        $this->user->email = $email;
    }

    public function checkDisposableEmail()
    {
        $email = $this->user->email;

        // Make API call to check if email is disposable
        $apiUrl = 'https://api.api-aries.online/v1/checkers/proxy/email/?email=' . urlencode($email);
        $headers = [
            'Type: TOKEN TYPE', // learn more: https://support.api-aries.online/hc/articles/1/3/3/email-checker
            'APITOKEN: API KEY', // learn more: https://support.api-aries.online/hc/articles/1/3/3/email-checker
        ];

        $curl = curl_init($apiUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode === 200) {
            $responseData = json_decode($response, true);

            // Check if the email is disposable according to the API response
            if (isset($responseData['disposable']) && strtolower($responseData['disposable']) === 'yes') {
                // Email is disposable, handle accordingly
                $this->user->error(\XF::phrase('disposable_email_address'));
                return false;
            } else {
                return true; // Email is not disposable
            }
        } else {
            // Failed to check disposable email via API
            $this->user->error(\XF::phrase('disposable_email_check_failed'));
            return false;
        }
    }
//////////////////////////////////////////////////////////////////

  
    // Existing code ...
  
public function setDob($day, $month, $year)
	{
		return $this->user->Profile->setDob($day, $month, $year);
	}


 // etc etc code ...
