<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    /**
     * Create a new message instance.
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this
        ->subject('New User Registration via Stripe')
        ->view('email.user_registered',[
            'userName' => (
                $this->user->first_Name . " " .
                $this->user->middle_Name . " " .
                $this->user->last_Name),
            'userEmail' => $this->user->email,
        ]);
      
    }
}
