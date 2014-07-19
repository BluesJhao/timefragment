<?php

class AuthorityController extends BaseController
{
    /**
     * 页面：登录
     * @return Response
     */
    public function getSignin()
    {
        return View::make('authority.signin');
    }

    /**
     * 动作：登录
     * @return Response
     */
    public function postSignin()
    {
        // 凭证
        $credentials = array('email' => Input::get('email'), 'password' => Input::get('password'));
        // 是否记住登录状态
        // $remember    = Input::get('remember-me', 1);
        // 验证登录
        if (Auth::attempt($credentials)) {
            // 登录成功，跳回之前被拦截的页面
            return Redirect::intended();
        } else {
            // 登录失败，跳回
            return Redirect::back()
                ->withInput()
                ->withErrors(array('attempt' => 'E-mail 或 用户名错误, 请重新登录'));
        }
    }

    /**
     * 动作：退出
     * @return Response
     */
    public function getSignout()
    {
        Auth::logout();
        return Redirect::to('/');
    }

    /**
     * 页面：注册
     * @return Response
     */
    public function getSignup()
    {
        return View::make('authority.signup');
    }

    /**
     * 动作：注册
     * @return Response
     */
    public function postSignup()
    {
        // 获取所有表单数据.
        $data = Input::all();
        // 创建验证规则
        $rules = array(
            'email'    => 'required|email|unique:users',
            'password' => 'required|alpha_dash|between:6,16|confirmed',
        );
        // 自定义验证消息
        $messages = array(
            'email.required'      => '请输入邮箱地址。',
            'email.email'         => '请输入正确的邮箱地址。',
            'email.unique'        => '此邮箱已被使用。',
            'password.required'   => '请输入密码。',
            'password.alpha_dash' => '密码格式不正确。',
            'password.between'    => '密码长度请保持在:min到:max位之间。',
            'password.confirmed'  => '两次输入的密码不一致。',
        );
        // 开始验证
        $validator = Validator::make($data, $rules, $messages);
        if ($validator->passes()) {
            // 验证成功，添加用户
            $user = new User;
            $user->email    = Input::get('email');
            $user->password = Input::get('password');
            if ($user->save()) {
                // 添加成功
                // 生成激活码
                $activation = new Activation;
                $activation->email = $user->email;
                $activation->token = str_random(40);
                $activation->save();
                // 发送激活邮件
                $with = array('activationCode' => $activation->token);
                Mail::send('authority.email.activation', $with, function ($message) use ($user) {
                    $message
                        ->to($user->email)
                        ->subject('时光碎片 账号激活邮件'); // 标题
                });
                // 跳转到注册成功页面，提示用户激活
                return Redirect::route('signupSuccess', $user->email);
            } else {
                // 添加失败
                return Redirect::back()
                    ->withInput()
                    ->withErrors(array('add' => '注册失败。'));
            }
        } else {
            // 验证失败，跳回
            return Redirect::back()
                ->withInput()
                ->withErrors($validator);
        }
    }

    /**
     * 页面：注册成功，提示激活
     * @param  string $email 用户注册的邮箱
     * @return Response
     */
    public function getSignupSuccess($email)
    {
        // 确认是否存在此未激活邮箱
        $activation = Activation::whereRaw("email = '{$email}'")->first();
        // 数据库中无邮箱，抛出404
        is_null($activation) AND App::abort(404);
        // 提示激活
        return View::make('authority.signupSuccess')->with('email', $email);
    }

    /**
     * 动作：激活账号
     * @param  string $activationCode 激活令牌
     * @return Response
     */
    public function getActivate($activationCode)
    {
        // 数据库验证令牌
        $activation = Activation::where('token', $activationCode)->first();
        // 数据库中无令牌，抛出404
        is_null($activation) AND App::abort(404);
        // 数据库中有令牌
        // 激活对应用户
        $user = User::where('email', $activation->email)->first();
        $user->activated_at = new Carbon;
        $user->save();
        // 删除令牌
        $activation->delete();
        // 激活成功提示
        return View::make('authority.activationSuccess');
    }

    /**
     * 页面：忘记密码，发送密码重置邮件
     * @return Response
     */
    public function getForgotPassword()
    {
        return View::make('authority.password.remind');
    }

    /**
     * 动作：忘记密码，发送密码重置邮件
     * @return Response
     */
    public function postForgotPassword()
    {
        // 调用系统提供的类
        $response = Password::remind(Input::only('email'), function ($m, $user, $token) {
            $m->subject('时光碎片 密码重置邮件'); // 标题
        });
        // 检测邮箱并发送密码重置邮件
        switch ($response) {
            case Password::INVALID_USER:
                return Redirect::back()->with('error', Lang::get($response));
            case Password::REMINDER_SENT:
                return Redirect::back()->with('status', Lang::get($response));
        }
    }

    /**
     * 页面：进行密码重置
     * @return Response
     */
    public function getReset($token)
    {
        // 数据库中无令牌，抛出404
        is_null(PassowrdReminder::where('token', $token)->first()) AND App::abort(404);
        return View::make('authority.password.reset')->with('token', $token);
    }

    /**
     * 动作：进行密码重置
     * @return Response
     */
    public function postReset()
    {
        // 调用系统自带密码重置流程
        $credentials = Input::only(
            'email', 'password', 'password_confirmation', 'token'
        );

        $response = Password::reset($credentials, function ($user, $password) {
            // 保存新密码
            $user->password = $password;
            $user->save();
            // 登录用户
            Auth::login($user);
        });

        switch ($response) {
            case Password::INVALID_PASSWORD:
                // no break
            case Password::INVALID_TOKEN:
                // no break
            case Password::INVALID_USER:
                return Redirect::back()->with('error', Lang::get($response));
            case Password::PASSWORD_RESET:
                return Redirect::to('/');
        }
    }

    /**
     * Action：Oauth 2.0 Signup
     * @return Response
     */
    public function getOauthSignup()
    {
        header("Content-type:text/html;charset=utf-8");
        session_start();

        include_once( app_path('api/weibo/config.php') );
        include_once( app_path('api/weibo/saetv2.ex.class.php') );

        $o = new SaeTOAuthV2( WB_AKEY , WB_SKEY );

        if (isset($_REQUEST['code'])) {
            $keys                 = array();
            $keys['code']         = $_REQUEST['code'];
            $keys['redirect_uri'] = WB_CALLBACK_URL;
            try {
                $token = $o->getAccessToken( 'code', $keys ) ;
            } catch (OAuthException $e) {
            }
        }

        if ($token) {
            $_SESSION['token'] = $token;
            setcookie( 'weibojs_'.$o->client_id, http_build_query($token) );

            $c            = new SaeTClientV2( WB_AKEY , WB_SKEY , $_SESSION['token']['access_token'] );
            $ms           = $c->home_timeline(); // Done
            $uid_get      = $c->get_uid();
            $uid          = $uid_get['uid'];
            $user_message = $c->show_user_by_id($uid);// 根据ID获取用户等基本信息
            $nickname     = $user_message['screen_name'];
            $password     = $_SESSION['token']['access_token'];
            $credentials  = array('email' => $uid, 'password' => $password);

            if (Auth::attempt($credentials))
            {
                // 登录成功，跳回之前被拦截的页面
                return Redirect::intended();
            } else {
                $user           = new User;
                $user->email    = $uid;
                $user->password = $_SESSION['token']['access_token'];
                $user->nickname = $nickname;
                $user->save();
                return View::make('authority.oauthSuccess');
            }

        } else {
           return View::make('authority.signup')
            ->withErrors(array('add' => '注册失败。'));;
        }

    }

    /**
    * View: Oauth Success
    * @param  string
    * @return Response
    */
    public function getOauthSuccess()
    {
        return View::make('authority.oauthSuccess');
    }

    /**
     * Action：Oauth QQ
     * @return Response
     */
    public function getOauthQQ()
    {
        include_once( app_path('api/qq/qqConnectAPI.php' ));
        $qc = new QC();

        $callback     = $qc->qq_callback();
        $openid       = $qc->get_openid();
        $access_token =  $qc->get_access_token();
        $arr          = $qc->get_user_info();
        $nickname     = $arr["nickname"];
        $credentials  = array('email' => $openid, 'password' => $access_token);

        if (Auth::attempt($credentials))
        {
            // 登录成功，跳回之前被拦截的页面
            return Redirect::intended();
        } else {
            $user           = new User;
            $user->email    = $openid;
            $user->password = $access_token;
            $user->nickname = $nickname;
            $user->save();
            return View::make('authority.oauthQQ');
        }

    }


}