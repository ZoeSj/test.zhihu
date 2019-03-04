<?php

namespace App;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Mail;
use Naux\Mail\SendCloudTemplate;

class User extends Authenticatable
{
    use Notifiable;
    public $timestamps = false;
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'confirmation_token'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @param $token
     */
    public function sendPasswordResetNotification($token)
    {
        // 模板变量
        $data = ['url' => url(config('app.url') . route('password.reset', $token, false)),];
        $template = new SendCloudTemplate('reset_mail', $data);

        Mail::raw($template, function ($message) {
            $message->from('shengjiamo@163.com', 'Laravel');
            $message->to($this->email);
        });
    }

    public function messages()
    {
        $this->hasMany(message::class, 'user_id', 'id');
    }
}
