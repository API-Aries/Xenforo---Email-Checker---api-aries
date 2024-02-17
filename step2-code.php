//////////////////////////////////////////


protected function finalSetup()
{
    $user = $this->user;

    if (!$user->getErrors() && $user->email && !$this->avatarUrl)
    {
        if ($this->app->options()->gravatarEnable)
        {
            $gravatarValidator = $this->app->validator('Gravatar');
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
 ////////////////////////////////////
