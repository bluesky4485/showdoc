<?php

namespace Api\Controller;

use Think\Controller;

class ExtLoginController extends BaseController
{


    // 根据用户名和LoginSecretKey登录
    public function bySecretKey()
    {
        $username = I("username");
        $key = I("key");
        $time = I("time");
        $token = I("token");
        $redirect = I("redirect");
        $name = I("name");

        if ($time < (time() - 60)) {
            $this->sendError(10101, "已过期");
            return;
        }
        $login_secret_key = D("Options")->get("login_secret_key");
        if (!$login_secret_key) return false;
        $new_token = md5($username . $login_secret_key . $time);
        if (!($token ===  $new_token)) {
            $this->sendError(10101, "token不正确");
            return;
        }

        $res = D("User")->where("( username='%s' ) ", array($username))->find();
        if (!$res) {
            $new_uid = D("User")->register($username, md5("savsnyjh" . time() . rand()));
            $res = D("User")->where("( username='%s' ) ", array($username))->find();
            if($name){
                D("User")->where(" uid = '$new_uid' ")->save(array("name" => $name));
            }

        }
        if ($res) {
            // var_dump($res); return ;
            if ($res['groupid'] == 1) {
                $this->sendError(10101, "为了安全，禁止管理员通过这种方式登录");
                return;
            }
            $uid = $res['uid'] ;
            if($name){

                D("User")->where(" uid = '$uid' ")->save(array("name" => $name));
            }
            D("User")->setLastTime($uid);

            unset($res['password']);
            session("login_user", $res);
            $token = D("UserToken")->createToken($uid, 60 * 60 * 24 * 180);
            cookie('cookie_token', $token, array('expire' => 60 * 60 * 24 * 180, 'httponly' => 'httponly')); //此处由服务端控制token是否过期，所以cookies过期时间设置多久都无所谓
            if ($redirect) {
                $redirect = urldecode($redirect);
                header("location:{$redirect}");
            } else {
                header("location:../web/#/item/index");
            }
        }
    }

    public function oauth2()
    {
        $this->checkComposerPHPVersion();
        $redirect = I("redirect");
        session('redirect', $redirect);
        $oauth2_open = D("Options")->get("oauth2_open");
        $oauth2_form = D("Options")->get("oauth2_form");
        $oauth2_form = htmlspecialchars_decode($oauth2_form);
        $oauth2_form = json_decode($oauth2_form, 1);

        if (!$oauth2_open) {
            echo "尚未启用oauth2";
            return;
        }


        $clientId = $oauth2_form['client_id'];
        $clientSecret = $oauth2_form['client_secret'];
        $redirectUri = $oauth2_form['redirectUri'];
        $urlAuthorize = $oauth2_form['protocol'] . "://" . $oauth2_form['host'] . $oauth2_form['authorize_path'];
        $urlAccessToken = $oauth2_form['protocol'] . "://" . $oauth2_form['host'] . $oauth2_form['token_path'];
        $urlResourceOwnerDetails = $oauth2_form['protocol'] . "://" . $oauth2_form['host'] . $oauth2_form['resource_path'];
        if (strstr($oauth2_form['userinfo_path'], "://")) {
            $urlUserInfo = $oauth2_form['userinfo_path'];
        } else {
            $urlUserInfo = $oauth2_form['protocol'] . "://" . $oauth2_form['host'] . $oauth2_form['userinfo_path'];
        }


        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => $clientId,    // The client ID assigned to you by the provider
            'clientSecret'            => $clientSecret,    // The client password assigned to you by the provider
            'redirectUri'             => $redirectUri,
            'urlAuthorize'            => $urlAuthorize,
            'urlAccessToken'          =>  $urlAccessToken,
            'urlResourceOwnerDetails' => $urlResourceOwnerDetails,
        ], [
            'httpClient' => new \GuzzleHttp\Client(['verify' => false]),
        ]);

        // If we don't have an authorization code then get one
        if (!isset($_GET['code'])) {

            // Fetch the authorization URL from the provider; this returns the
            // urlAuthorize option and generates and applies any necessary parameters
            // (e.g. state).
            $authorizationUrl = $provider->getAuthorizationUrl();

            // Get the state generated for you and store it to the session.
            $_SESSION['oauth2state'] = $provider->getState();

            // Redirect the user to the authorization URL.
            header('Location: ' . $authorizationUrl);
            exit;

            // Check given state against previously stored one to mitigate CSRF attack
        } elseif (empty($_GET['state']) || (isset($_SESSION['oauth2state']) && $_GET['state'] !== $_SESSION['oauth2state'])) {

            if (isset($_SESSION['oauth2state'])) {
                unset($_SESSION['oauth2state']);
            }

            exit('Invalid state');
        } else {

            try {

                // Try to get an access token using the authorization code grant.
//                $accessToken = $provider->getAccessToken('authorization_code', [
//                    'code' => $_GET['code']
//                ]);

                $oCurl = curl_init();
                curl_setopt($oCurl, CURLOPT_URL, $urlAccessToken);   //设置网址
                curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);  //将curl_exec的结果返回
                curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
                curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, FALSE);
                curl_setopt($oCurl, CURLOPT_HEADER, 0);         //是否输出返回头信息
                $basicAuth = base64_encode("$clientId:$clientSecret");

                // 设置请求头
                $headers = [
                    "Authorization: Basic {$basicAuth}",  // 设置 Bearer Token
                    "HzTech-Requested-With: HzTechHttpRequest",
                    "User-Agent: showdoc",                           // 设置用户代理
                    "Accept: application/json",                      // 设置响应数据类型
                ];
                curl_setopt($oCurl, CURLOPT_HTTPHEADER, $headers);   // 将自定义头部信息添加到请求中
                $postFields = [
                    'grant_type'    => 'authorization_code',
                    'code'          => $_GET['code'],
                    'redirect_uri'  => $redirectUri,
                ];
                curl_setopt($oCurl, CURLOPT_POST, 1);                         // 设置 POST 请求
                curl_setopt($oCurl, CURLOPT_POSTFIELDS, http_build_query($postFields)); // 设置 POST 数据
                $accessToken = curl_exec($oCurl);   //执行
                curl_close($oCurl);          //关闭会话


                // We have an access token, which we may use in authenticated
                // requests against the service provider's API.
                //echo 'Access Token: ' . $accessToken->getToken() . "<br>";
                //echo 'Refresh Token: ' . $accessToken->getRefreshToken() . "<br>";
                //echo 'Expired in: ' . $accessToken->getExpires() . "<br>";
                // echo 'Already expired? ' . ($accessToken->hasExpired() ? 'expired' : 'not expired') . "<br>";

                $res_array = json_decode($accessToken, true);

                if ($res_array) {
                    $username = '';
                    // 登录的token
                    $hz_token = '';
                    // token的前缀
                    $token_type = '';
                    // 部门集合
                    $deptList = '';
                    if ($res_array['access_token']) {
                        $hz_token = $res_array['access_token'];
                    }
                    if ($res_array['token_type']) {
                        $token_type = $res_array['token_type'];
                    }
                    if ($res_array['dept_id']) {
                        $deptList = $res_array['dept_id'];
                    }

                    if ($res_array['preferred_username']) {
                        $username = $res_array['preferred_username'];
                    }
                    if ($res_array['name']) {
                        $username = $res_array['name'];
                    }
                    if ($res_array['user_name']) {
                        $username = $res_array['user_name'];
                    }
                    if ($res_array['login']) {
                        $username = $res_array['login'];
                    }
                    if (!$username) {
                        echo "返回信息中无法获取用户名。返回的内容如下：" . $res_array['msg'];
                        return;
                    }
                    $info = D("User")->where("username='%s'", array($username))->find();
                    if (!$info) {
                        D("User")->register($username, md5($username . time() . rand()));
                        $info = D("User")->where("username='%s'", array($username))->find();
                        if($res_array['nick_name']){
                            D("User")->where("username='%s'", array($username))->save(array("name" =>$res_array['nick_name']));
                        }
                        // 创建一个全部用户所在的team,用于给所有人权限
                        $allTeam = D("Team")->where("team_name='%s'",array('汇智全体人员'))->find();
                        $userAdmin = D("User")->where("username='%s'", array('admin'))->find();
                        if (!$allTeam) {
                            // 设置管理员
                            $teamData['username'] = 'admin';
                            $teamData['uid'] = $userAdmin['uid'];
                            $teamData['team_name'] = '汇智全体人员';
                            $teamData['addtime'] = time();
                            $id = D("Team")->add($teamData);
                            $this->saveTeamMember($userAdmin['uid'],'admin',$id,2);
                        }else {
                            $this->saveTeamMember($info['uid'],$info['username'],$allTeam['id'],1);
                        }

                        // 使用逗号分隔字符串
                        $result = explode(",", $deptList);

                        // 遍历数组  获取该用户的顶级部门
                        foreach ($result as $value) {
                            $deptCurl = curl_init();
                            curl_setopt($deptCurl, CURLOPT_URL, $oauth2_form['protocol'] . "://" . $oauth2_form['host'] . "/api/hztech-system/dept/getTopParentDept?id=" . $value);   //设置网址
                            curl_setopt($deptCurl, CURLOPT_RETURNTRANSFER, 1);  //将curl_exec的结果返回
                            curl_setopt($deptCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
                            curl_setopt($deptCurl, CURLOPT_SSL_VERIFYHOST, FALSE);
                            curl_setopt($deptCurl, CURLOPT_HEADER, 0);         //是否输出返回头信息
                            // 设置请求头
                            $headers = [
                                "hztech-auth: {$token_type} ${hz_token}",   //  Token
                                "HzTech-Requested-With: HzTechHttpRequest",
                                "User-Agent: showdoc",                     // 自定义用户代理
                                "Accept: application/json"                // 接受 JSON 响应
                            ];
                            curl_setopt($deptCurl, CURLOPT_HTTPHEADER, $headers);
                            // 执行 cURL 请求
                            $response = curl_exec($deptCurl);
                            curl_close($deptCurl);    //关闭会话

                            $dept_array = json_decode($response, true);

                            $deptTeam = D("Team")->where("team_name='%s'",array($dept_array['data']['deptName']))->find();
                            if (!$deptTeam) {
                                $deptReamData['username'] = 'admin';
                                $deptReamData['uid'] = $userAdmin['uid'];
                                $deptReamData['team_name'] = $dept_array['data']['deptName'];
                                $deptReamData['addtime'] = time();
                                $id = D("Team")->add($deptReamData);
                                $this->saveTeamMember($userAdmin['uid'],'admin',$id,2);
                                if ($userAdmin['username'] !== 'admin'){
                                    $this->saveTeamMember($info['uid'],$info['username'],$id,1);
                                }
                            }else{
                                $this->saveTeamMember($info['uid'],$info['username'],$deptTeam['id'],1);
                            }
                        }
                    }

                    D("User")->setLastTime($info['uid']);
                    unset($info['password']);
                    session("login_user", $info);
                    $token = D("UserToken")->createToken($info['uid'], 60 * 60 * 24 * 180);
                    cookie('cookie_token', $token, array('expire' => 60 * 60 * 24 * 180, 'httponly' => 'httponly')); //此处由服务端控制token是否过期，所以cookies过期时间设置多久都无所谓
                    if (session('redirect')) {
                        $redirect = urldecode(session('redirect'));
                        header("location:{$redirect}");
                        session('redirect', null);
                    } else {
                        header("location:../web/#/item/index");
                    }
                } else {
                    echo "登录成功但无法获取用户信息" . $res_array;
                }
            } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {

                // Failed to get the access token or user details.
                exit($e->getMessage());
            }
        }
    }

    // 保存用户跟团队的关联关系
    protected function saveTeamMember($uid,$username, $team_id,$team_member_group_id)
    {
        $data = array();
        $data['team_id'] = $team_id;
        $data['member_uid'] = $uid;
        $data['member_username'] = $username;
        $data['team_member_group_id'] = $team_member_group_id; //默认添加的权限为1，即编辑权限
        $data['addtime'] = time();
        $id = D("TeamMember")->add($data);

        //检查该团队已经加入了哪些项目
        $teamItems = D("TeamItem")->where("  team_id = '$team_id' ")->select();
        if ($teamItems) {
            foreach ($teamItems as $key2 => $value2) {
                $data = array(
                    "team_id" => $team_id,
                    "member_uid" => $uid,
                    "member_username" => $username,
                    "item_id" => $value2['item_id'],
                    "member_group_id" => 1, //默认添加的权限为1，即编辑权限
                    "addtime" => time()
                );
                D("TeamItemMember")->add($data);
            }
        }

    }


    public function cas()
    {
        $this->checkComposerPHPVersion();
        define("CAS_VERSION_1_0", '1.0');
        define("CAS_VERSION_2_0", '2.0');
        define("CAS_VERSION_3_0", '3.0');

        # 2 开启phpCAS debug
        \phpCAS::setDebug();

        # 3 初始化phpCAS,参数说明：
        # a) CAS协议版本号
        # b) cas server的域名
        # c) cas server的端口号
        # d) cas server的项目访问路径
        \phpCAS::client(CAS_VERSION_2_0, '192.168.8.160', 8443, '/maxkey/authz/cas/');

        # 4 开启设置证书验证。如果是开发环境可将此注释，如果是生产环境为了安全性建议将此开启
        // phpCAS::setCasServerCACert($cas_server_ca_cert_path);

        # 5 不为CAS服务器设置SSL验证
        # 为了快速测试，您可以禁用CAS服务器的SSL验证。此建议不建议用于生产环境。验证CAS服务器对CAS协议的安全性至关重要！
        \phpCAS::setNoCasServerValidation();

        # 6 这里会检测服务器端的退出的通知，就能实现php和其他语言平台间同步登出了
        # 处理登出请求。cas服务端会发送请求通知客户端。如果没有同步登出，可能是服务端跟客户端无法通信（比如我的客户端是localhost, 服务端在云上）
        \phpCAS::handleLogoutRequests();

        # 7 进行CAS服务验证，这个方法确保用户是否验证过，如果没有验证则跳转到验证界面
        # 这个是强制认证模式，查看 CAS.php 可以找到几种不同的方式：
        # a) forceAuthentication - phpCAS::forceAuthentication();
        # b) checkAuthentication - phpCAS::checkAuthentication();
        # c) renewAuthentication - phpCAS::renewAuthentication();
        # 根据自己需要调用即可。
        $auth = \phpCAS::forceAuthentication();
        if ($auth) {
            var_dump($auth);
            return;
            # 8 验证通过，或者说已经登陆系统，可进行已经登陆之后的逻辑处理...
            # 获得登陆CAS用户的名称
            $user_name = \phpCAS::getUser();
            echo $user_name . '已经成功登陆...<br>';

            # 9 你还可打印保存的phpCAS session信息
            print_r($_SESSION);

            # 10 还可获取有关已验证用户的属性,例如：$uid = phpCAS::getAttribute('id');
            $attr = \phpCAS::getAttributes();
            print_r($attr);

            # 11 进行退出的相关操作
            # 在你的PHP项目中处理完相应的退出逻辑之后，还需执行phpCAS::logout()进行CAS系统的退出
            # 当我们访问cas服务端的logout的时候，cas服务器会发送post请求到各个已经登录的客户端
            //phpCAS::logout();

            # 登出方法一：登出成功后跳转的地址
            //phpCAS::setServerLoginUrl("https://192.168.1.120:80/cas/logout?embed=true&service=http://localhost/phpCasClient/user.php?a=login");
            //phpCAS::logout();
            # 登出方法二：退出登录后返回地址
            //$param = array("service" => "http://cas.x.com");
            //phpCAS::logout($param);

        } else {
            # 12 验证未通过，说明未进行登陆
            # 将会跳转回你配置的CAS SSO SERVER服务的域名；
            # 在你输入正确的用户名和密码之后CAS会自动跳转回service=http%3A%2F%2Fcas.x.com%2F此地址
            # 在此你可以处理验证未通过的各种逻辑
            echo '还未登陆，跳转到CAS进行登陆...<br>';
        }
    }
}
