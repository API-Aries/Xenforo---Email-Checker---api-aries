<?php

namespace XF\Service\User;

use XF\CustomField\Set;
use XF\Entity\User;
use XF\Repository\ChangeLogRepository;
use XF\Repository\IpRepository;
use XF\Repository\PreRegActionRepository;
use XF\Repository\TrophyRepository;
use XF\Repository\UserGroupPromotionRepository;
use XF\Repository\UserRepository;
use XF\Service\AbstractService;
use XF\Service\ValidateAndSavableTrait;
use XF\Util\File;
use XF\Validator\Gravatar;
use XF\Validator\Url;

use function intval;

class RegistrationService extends AbstractService
{
	use ValidateAndSavableTrait;

	/**
	 * @var User
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
		$this->user = $this->app->repository(UserRepository::class)->setupBaseUser();
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
				[$relation, $relationKey] = explode('.', $entityKey, 2);
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
		if ($doPasswordConfirmation)
		{
			if ($password !== $passwordConfirm)
			{
				$this->user->error(\XF::phrase('passwords_did_not_match'));
				return false;
			}
		}

		if ($this->user->Auth->setPassword($password))
		{
			$this->user->Profile->password_date = \XF::$time;
		}
		return true;
	}

	public function setNoPassword()
	{
		$this->user->Auth->setNoPassword();
		$this->user->Profile->password_date = \XF::$time;
	}

	public function setDob($day, $month, $year)
	{
		return $this->user->Profile->setDob($day, $month, $year);
	}

	public function setCustomFields(array $values)
	{
		/** @var Set $fieldSet */
		$fieldSet = $this->user->Profile->custom_fields;

		$fieldDefinition = $fieldSet->getDefinitionSet()
			->filterEditable($fieldSet, 'user')
			->filter('registration');

		$customFieldsShown = array_keys($fieldDefinition->getFieldDefinitions());

		if ($customFieldsShown)
		{
			$fieldSet->bulkSet($values, $customFieldsShown);
		}
	}

	public function setFromInput(array $input)
	{
		$this->setMapped($input);

		if (isset($input['password']))
		{
			$password = $input['password'];
			if (isset($input['password_confirm']))
			{
				$passwordConfirm = $input['password_confirm'];
				$doPasswordConfirmation = true;
			}
			else
			{
				$passwordConfirm = '';
				$doPasswordConfirmation = false;
			}

			$this->setPassword($password, $passwordConfirm, $doPasswordConfirmation);
		}

		if (isset($input['dob_day'], $input['dob_month'], $input['dob_year']))
		{
			$day = $input['dob_day'] ?? 0;
			$month = $input['dob_month'] ?? 0;
			$year = $input['dob_year'] ?? 0;

			$this->setDob($day, $month, $year);
		}

		if (isset($input['custom_fields']))
		{
			$this->setCustomFields($input['custom_fields']);
		}

		if (isset($input['email_choice']))
		{
			$this->setReceiveAdminEmail($input['email_choice']);
			$this->setReceiveActivitySummary($input['email_choice']);
		}
	}
	
	public function setEmail($email)
    {
        $this->user->email = $email;
    }

    public function checkDisposableEmail()
{
    $email = $this->user->email;

    // Make API call to check if email is disposable
    $apiUrl = 'https://api.api-aries.com/v1/checkers/proxy/email/?email=' . urlencode($email);
    $headers = [
        'APITOKEN: API KEY', // learn more: https://support.api-aries.com/hc/articles/1/3/3/email-checker 
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
            $this->user->error(\XF::phrase('Sorry we dont allow disposable email domains to prevent spam'));
            return false;
        } else {
            return true; // Email is not disposable
        }
    } else {
        // Failed to check disposable email via API
        $this->user->error(\XF::phrase('disposable email checker api issue'));
        return false;
    }
}


	public function setReceiveAdminEmail($choice)
	{
		$this->user->Option->receive_admin_email = $choice;
	}

	public function setReceiveActivitySummary(bool $choice)
	{
		$this->user->last_summary_email_date = $choice ? \XF::$time : null;
	}

	public function setAvatarUrl($url)
	{
		$this->avatarUrl = $url;
	}

	public function setPreRegActionKey($key)
	{
		$this->preRegActionKey = $key;
	}

	public function getPreRegContent()
	{
		return $this->preRegContent;
	}

	public function checkForSpam()
	{
		$user = $this->user;

		$userChecker = $this->app->spam()->userChecker();
		$userChecker->check($user, ['preRegActionKey' => $this->preRegActionKey]);

		$decision = $userChecker->getFinalDecision();
		switch ($decision)
		{
			case 'denied':
				$phrase = \XF::phrase('spam_prevention_registration_rejected')->render();
				$user->setUserRejected($this->app->stringFormatter()->wholeWordTrim($phrase, 200));
				break;

			case 'moderated':
				$user->user_state = 'moderated';
				break;
		}
	}

	public function skipEmailConfirmation($skip = true)
	{
		$this->skipEmailConfirm = $skip;
	}

	protected function _validate()
	{
		$this->finalSetup();

		$user = $this->user;
		$user->preSave();

		$this->applyExtraValidation();

		return $user->getErrors();
	}

	protected function finalSetup()
	{
		$user = $this->user;

		if (!$user->getErrors() && $user->email && !$this->avatarUrl)
		{
			if ($this->app->options()->gravatarEnable)
			{
				$gravatarValidator = $this->app->validator(Gravatar::class);
				$gravatar = $gravatarValidator->coerceValue($user->email);
				if ($gravatarValidator->isValid($gravatar))
				{
					$user->gravatar = $gravatar;
				}
			}
		}

		$this->setInitialUserState();
        $this->setPolicyAcceptance();

    // Check if email is disposable
    if (!$this->checkDisposableEmail()) {
        return; // Stop registration process if email is disposable
    }
}

	protected function setInitialUserState()
	{
		$user = $this->user;
		$options = $this->app->options();

		if ($user->user_state != 'valid')
		{
			return; // We have likely already set the user state elsewhere, e.g. spam trigger
		}

		if ($options->registrationSetup['emailConfirmation'] && !$this->skipEmailConfirm)
		{
			$user->user_state = 'email_confirm';
		}
		else if ($options->registrationSetup['moderation'])
		{
			$user->user_state = 'moderated';
		}
		else
		{
			$user->user_state = 'valid';
		}
	}

	protected function setPolicyAcceptance()
	{
		$user = $this->user;

		if ($this->app->container('privacyPolicyUrl'))
		{
			$user->privacy_policy_accepted = \XF::$time;
		}
		if ($this->app->container('tosUrl'))
		{
			$user->terms_accepted = \XF::$time;
		}
	}

	protected function applyExtraValidation()
	{
		$user = $this->user;
		$options = $this->app->options();
		$age = $user->Profile->getAge(true);

		if ($options->registrationSetup['requireDob'])
		{
			if (!$age)
			{
				// incomplete dob
				$user->error(\XF::phrase('please_enter_valid_date_of_birth'), 'dob');
			}
			else if ($options->registrationSetup['minimumAge'])
			{
				if ($age < intval($options->registrationSetup['minimumAge']))
				{
					$user->error(\XF::phrase('sorry_you_too_young_to_create_an_account'), 'dob');
				}
			}
		}

		if (!empty($options->registrationSetup['requireLocation']) && !$user->Profile->location)
		{
			$user->error(\XF::phrase('please_enter_valid_location'), 'location');
		}
	}

	protected function _save()
	{
		$user = $this->user;

		$user->save();

		$this->app->spam()->userChecker()->logSpamTrigger('user', $user->user_id);

		if ($this->logIp)
		{
			$ip = ($this->logIp === true ? $this->app->request()->getIp() : $this->logIp);
			$this->writeIpLog($ip);
		}

		if ($this->preRegActionKey)
		{
			/** @var PreRegActionRepository $preRegActionRepo */
			$preRegActionRepo = $this->repository(PreRegActionRepository::class);
			$preRegActionRepo->associateActionWithUser($this->preRegActionKey, $user->user_id);
		}

		$this->writeInitialChangeLogs();
		$this->updateUserAchievements();
		$this->sendRegistrationContact();

		if ($this->avatarUrl)
		{
			// Only apply the avatar if the user would have permission. This reads the permission set directly
			// to ensure that we check their "real" permissions. Otherwise, if this is set and the user hasn't gone
			// directly to the valid state, the permission is likely to be a false negative.
			$permissions = $this->app->permissionCache()->getPermissionSet(
				$user->getValue('permission_combination_id')
			);
			if ($permissions->hasGlobalPermission('avatar', 'allowed'))
			{
				$this->applyAvatarFromUrl($this->avatarUrl);
			}
		}

		return $user;
	}

	protected function writeIpLog($ip)
	{
		$user = $this->user;

		/** @var IpRepository $ipRepo */
		$ipRepo = $this->repository(IpRepository::class);
		$ipRepo->logIp($user->user_id, $ip, 'user', $user->user_id, 'register');
	}

	protected function writeInitialChangeLogs()
	{
		/** @var ChangeLogRepository $changeLogRepo */
		$changeLogRepo = $this->repository(ChangeLogRepository::class);

		$user = $this->user;

		$changes = [];

		if ($this->app->options()->registrationSetup['requireEmailChoice'])
		{
			$changes['receive_admin_email'] = [0, $user->Option->receive_admin_email ? 1 : 0];
		}
		if ($this->app->container('privacyPolicyUrl'))
		{
			$changes['privacy_policy_accepted'] = [0, \XF::$time];
		}
		if ($this->app->container('tosUrl'))
		{
			$changes['terms_accepted'] = [0, \XF::$time];
		}

		if ($changes)
		{
			$changeLogRepo->logChanges('user', $user->user_id, $changes, $user->user_id);
		}
	}

	protected function updateUserAchievements()
	{
		/** @var UserGroupPromotionRepository $userGroupPromotionRepo */
		$userGroupPromotionRepo = $this->repository(UserGroupPromotionRepository::class);
		$userGroupPromotionRepo->updatePromotionsForUser($this->user);

		if ($this->app->options()->enableTrophies)
		{
			/** @var TrophyRepository $trophyRepo */
			$trophyRepo = $this->repository(TrophyRepository::class);
			$trophyRepo->updateTrophiesForUser($this->user);
		}
	}

	protected function sendRegistrationContact()
	{
		$user = $this->user;

		if ($user->user_state == 'email_confirm')
		{
			/** @var EmailConfirmationService $emailConfirmation */
			$emailConfirmation = $this->service(EmailConfirmationService::class, $user);
			$emailConfirmation->triggerConfirmation();
		}
		else if ($user->user_state == 'valid')
		{
			/** @var RegistrationCompleteService $regComplete */
			$regComplete = $this->service(RegistrationCompleteService::class, $user);
			$regComplete->triggerCompletionActions();
			$this->preRegContent = $regComplete->getPreRegContent();
		}
	}

	public function applyAvatarFromUrl($url)
	{
		if (!$this->user->user_id)
		{
			throw new \LogicException("User is not saved yet");
		}

		$app = $this->app;

		$validator = $app->validator(Url::class);
		$url = $validator->coerceValue($url);
		if (!$validator->isValid($url))
		{
			return false;
		}

		$tempFile = File::getTempFile();
		if ($app->http()->reader()->getUntrusted($url, [], $tempFile))
		{
			/** @var AvatarService $avatarService */
			$avatarService = $this->service(AvatarService::class, $this->user);
			$avatarService->logIp(false);
			if (!$avatarService->setImage($tempFile))
			{
				return false;
			}
			return $avatarService->updateAvatar();
		}
		else
		{
			return false;
		}
	}
}
