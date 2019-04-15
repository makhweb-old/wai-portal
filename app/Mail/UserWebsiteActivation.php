<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Lang;

/**
 * User mail for website activation notification.
 */
class UserWebsiteActivation extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The user to notify.
     *
     * @var User the user
     */
    protected $user;

    /**
     * The activated website.
     *
     * @var Website the website
     */
    protected $website;

    /**
     * Mail constructor.
     *
     * @param User $user the user
     * @param Website $website the website
     */
    public function __construct(User $user, Website $website)
    {
        $this->user = $user;
        $this->website = $website;
    }

    /**
     * Build the mail.
     *
     * @return UserWebsiteActivation the mail
     */
    public function build(): UserWebsiteActivation
    {
        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->subject(trans('mail.website.activated.user.subject'))
            ->markdown('mail.website_activated_user_email')->with([
                'locale' => Lang::getLocale(),
                'fullName' => $this->user->full_name,
                'website' => $this->website->name,
            ]);
    }
}
