// Auth controller class, used in Slim framework based app (personal project), written in PHP 7.2
<?php
namespace Classroom;
use \Slim\Http\Request;
use \Slim\Http\Response;
use Psr\Container\ContainerInterface;
use Common\Notify\Mailer;

class Auth extends Controller
{
  protected $User;

  public function __construct(ContainerInterface $container)
  {
      parent::__construct($container);
      $this->User = new \Common\User($this->container['conf']);
  }

  public function init(Request $request, Response $response, callable $next)
	{
		$route = $request->getAttribute('route');

		$Session = \Common\Session::getInstance($this->container['conf']);
		$u = $Session->getUser();
		ACL::getInstance($this->container, $Session['user']['id']?:null,$Session['user']['groups']?:[]);
		return $next($request, $response);
	}

  public function logout($request, $response)
	{
		$this->auth->logout();
		return $response->withRedirect($this->router->pathFor('home'));
	}
	public function getLogin($request, $response)
	{
        $this->setViewDataArray([
            'title' => 'Авторизация',
            'scripts' => $this->assets['home']['scripts'] ?? [],
            'styles' => $this->assets['home']['styles'] ?? [],
        ]);
        $this->setInnerTemplate('auth/login.twig');
		return $this->render($response);
	}
	public function postLogin($request, $response)
	{
		$auth = $this->auth->attempt(
			$request->getParam('email'),
			$request->getParam('password')
		);
		if (!$auth) {
      $errors = $this->auth->getErrors();
      if($errors[0]['code']==401){
        $this->flash->addMessage('error', $errors[0]['message']);
  			return $response->withRedirect($this->router->pathFor('auth.activation'));
      }
      if($errors[0]['code']==423){
        $this->flash->addMessage('error', $errors[0]['message']);
  			return $response->withRedirect($this->router->pathFor('home'));
      }
			$this->flash->addMessage('error', 'Неправильный email или пароль');
			return $response->withRedirect($this->router->pathFor('auth.login'));
		}
    $this->flash->addMessage('info','Welcome!');
		return $response->withRedirect($this->router->pathFor('home'));
	}

  public function registration( Request $request, Response $response, array $args ) {
      // join - регистрация ученика
      $join = (!empty($args['_join']));
      $pagetitle = $join?'Ваш преподаватель ждёт вас':'Ваш виртуальный класс ждёт вас';
    if($request->isPost()){
      //обработка формы
      $form_errors=[];
      if(!empty($request->getParam('email'))){
        $form_data['email'] = trim($request->getParam('email'));
        $email = true;
        if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
          $email = false;
          $form_errors['email'][] = 'Указан некорректный email.';
        }
      }
      if(!empty($request->getParam('fullname'))){
          $form_data['fullname'] = trim($request->getParam('fullname'));
          $fullname = true;
          if (!preg_match("/^[а-яa-zё\s]+$/iu", $form_data['fullname'])) {
            $fullname = false;
            $form_errors['fullname'][] = 'Некорректные символы в имени. Допустимы только А-Я,A-Z.';
          }
      }

      if(!empty($request->getParam('password'))){
        $form_data['password'] = $request->getParam('password');
        $password = true;
        if (!preg_match('/^(?=.*\d)(?=.*[A-Za-z])[0-9A-Za-z!@#$%]{8,30}$/', $form_data['password'])) {
          $password = false;
          $form_errors['password'][] = 'Пароль должен содержать хотя бы одну цифру, букву, быть длиной от 8 до 30 символов. Допустимы только 0-9A-Za-z!@#$%.';
        }
      }
        if($join){
            $join_uid = $request->getParam('i');
            $join_teacher = \Common\Helper::int_base32($join_uid,false);
            $join_code = $request->getParam('c');
            $check_join = $this->User->checkInvite($join_teacher,$join_code);
            if (!$check_join) {
                $form_errors['join'][] = 'Некорректная ссылка регистрации.';
            }
        }
      // прямая регистрация доступна только для преподавателя (группа 1), join-регистрация по токену доступна только ученикам (группа 2)
      $form_data['group'] = $join?2:1;
      $this->setViewDataArray([
  			'title' => $pagetitle,
        'form' => $form_data,
        'errors' => $form_errors,
        'scripts' => $this->assets['home']['scripts'] ?? [],
        'styles' => $this->assets['home']['styles'] ?? [],
  		]);
      if ($email && $fullname && $password && empty($form_errors)) {
        $user_id = $this->User->create([
    			'email' => $form_data['email'],
    			'fullname' => $form_data['fullname'],
    			'password' => $form_data['password'],
          'groups' => [$form_data['group']]
    		]);

        if($user_id){

            if($join && !empty($join_teacher)){
                $accepted1 = $this->User->accept($join_teacher,$user_id,'student');
                $accepted2 = $this->User->accept($user_id,$join_teacher,'teacher');
            }

          $user_data = $this->User->find($user_id);

          $ok = Mailer::getInstance($this->container['conf'])->send('registration', $user_data);
          if($ok){
            $this->container->flash->addMessage('info','Вы зарегистрированы, пройдите по ссылке в письме чтобы активировать аккаунт.');
            return $response->withRedirect($this->router->pathFor('home'));
          } else {
            $this->container->flash->addMessage('error','Не удалось отправить письмо со ссылкой для активации аккаунта.');
            return $response->withRedirect($this->router->pathFor('auth.activation'));
          }
        } else {
          $errors = $this->User->getErrors();
          $msg=$errors[0]['message'] ?? 'При регистрации произошла ошибка.';
          $this->container->flash->addMessage('error',$msg);
          return $response->withRedirect($this->router->pathFor('auth.registration'));
        }

      }
    }
    $this->setViewDataArray([
        'title' => $pagetitle,
        'scripts' => $this->assets['home']['scripts'] ?? [],
        'styles' => $this->assets['home']['styles'] ?? [],
    ]);
    $this->setInnerTemplate('auth/registration.twig');
		return $this->render($response);
  }

  public function activation( Request $request, Response $response, array $args ) {
    $form_data=$form_errors=[];
    if($request->isPost()){
      //TODO обработка формы повторной отправки письма со ссылкой для активации аккаунта
      if(!empty($request->getParam('email'))){
        $form_data['email'] = $request->getParam('email');
        if (filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
          $user_data = $this->User->find($form_data['email'],'email');
          if($user_data) {
            if((int)$user_data['status']==0){
              $ok = Mailer::getInstance($this->container['conf'])->send('registration', $user_data);
              if($ok){
                //TODO редирект на страницу "вы зарегистрированы, пройдите по ссылке в письме чтобы активировать аккаунт"
                $this->container->flash->addMessage('info','Пройдите по ссылке в письме чтобы активировать аккаунт.');
                return $response->withRedirect($this->router->pathFor('home'));
              } else {
                //TODO страница "отправить email для активации повторно"
                $this->container->flash->addMessage('error','Не удалось отправить письмо со ссылкой для активации аккаунта.');
                return $response->withRedirect($this->router->pathFor('auth.activation'));
              }
            } else {
              $form_errors['email'][] = 'Пользователь с таким email уже активирован.';
            }
          } else {
            $form_errors['email'][] = 'Пользователь с таким email не найден.';
          }
        } else {
          $form_errors['email'][] = 'Указан некорректный email.';
        }
      }
    }
    if($request->isGet()){
      if(!empty($request->getParam('id')) && !empty($request->getParam('verifycode'))){
        $user_id = (int)$request->getParam('id');
        $code = substr($request->getParam('verifycode'),0,64);
        $activate = $this->User->activate($user_id, $code);
        if($activate){
          $this->container->flash->addMessage('info','Аккаунт успешно активирован');
          return $response->withRedirect($this->router->pathFor('home'));
        } else {
          $this->container->flash->addMessage('error','Не удалось активровать аккаунт, попробуйте получить новый код активации.');
          return $response->withRedirect($this->router->pathFor('auth.activation'));
        }
      }
    }
    $this->setViewDataArray([
      'title' => 'Активация аккаунта',
      'form' => $form_data,
      'errors' => $form_errors,
      'scripts' => $this->assets['home']['scripts'] ?? [],
      'styles' => $this->assets['home']['styles'] ?? [],
    ]);
    $this->setInnerTemplate('auth/activation.twig');
    return $this->render($response);
  }

  // страница восстановления пароля
    public function resetpass( Request $request, Response $response, array $args ) {
        $resetpass=$reset=false;
        $form_data=$form_errors=[];
        if($request->isPost()){
            if(!empty($request->getParam('email'))){
                $form_data['email'] = $request->getParam('email');
                if (filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
                    $user_data = $this->User->find($form_data['email'],'email');
                    if($user_data) {
                        if((int)$user_data['status']==1){
                            $ok=false;
                            $resetcode = \Common\Helper::randomStr(64,"abcdefghijklnmopqrstuvwxyzABCDEFGHIJKLNMOPQRSTUVWXYZ0123456789#");
                            $upd = $this->User->update($user_data['id'],['resetcode'=>$resetcode]);
                            if($upd) {
                                $user_data['resetcode']=$resetcode;
                                $ok = Mailer::getInstance($this->container['conf'])->send('resetpass', $user_data);
                            }
                            if($ok){
                                $this->container->flash->addMessage('info','Пройдите по ссылке в письме чтобы восстановить пароль.');
                                return $response->withRedirect($this->router->pathFor('home'));
                            } else {
                                $this->container->flash->addMessage('error','Не удалось отправить письмо со ссылкой для восстановления пароля.');
                                return $response->withRedirect($this->router->pathFor('auth.resetpass'));
                            }
                        } else {
                            $form_errors['email'][] = 'Пользователь с таким email ещё не активирован.';
                        }
                    } else {
                        $form_errors['email'][] = 'Пользователь с таким email не найден.';
                    }
                } else {
                    $form_errors['email'][] = 'Указан некорректный email.';
                }
            }
            if(!empty($request->getParam('password'))){
                $form_data['password'] = $request->getParam('password');
                $password = false;
                if (!preg_match('/^(?=.*\d)(?=.*[A-Za-z])[0-9A-Za-z!@#$%]{8,30}$/', $form_data['password'])) {
                    $form_errors['password'][] = 'Пароль должен содержать хотя бы одну цифру, букву, быть длиной от 8 до 30 символов. Допустимы только 0-9A-Za-z!@#$%.';
                }
                if(!empty($request->getParam('repeat'))){
                    $form_data['repeat'] = $request->getParam('repeat');
                }
                if($form_data['password']!==$form_data['repeat']){
                    $password = false;
                    $form_errors['repeat'][] = 'Введенный пароль не совпадает с первым.';
                } else {
                    $password = true;
                }
                $user_id = (int)$request->getParam('id');
                $code = substr($request->getParam('resetcode'),0,64);
                $checkreset = $this->User->checkResetPassCode($user_id, $code);
                if($checkreset){
                    $resetpass = true;
                    $reset = [
                        'id'=>$user_id,
                        'code'=>$code
                    ];
                }
                if($password && $checkreset){
                    $upd = $this->User->update($user_id,['password'=>$form_data['password'],'resetcode'=>false]);
                    //var_dump($upd); exit;
                    if($upd){
                        $this->container->flash->addMessage('info','Новый пароль успешно установлен');
                        return $response->withRedirect($this->router->pathFor('home'));
                    } else {
                        $this->container->flash->addMessage('error','Не удалось изменить пароль, попробуйте получить новый код восстановления.');
                        return $response->withRedirect($this->router->pathFor('auth.resetpass'));
                    }

                }
            }
        }
        if($request->isGet()){
            if(!empty($request->getParam('id')) && !empty($request->getParam('resetcode'))){
                $user_id = (int)$request->getParam('id');
                $code = substr($request->getParam('resetcode'),0,64);
                $checkreset = $this->User->checkResetPassCode($user_id, $code);
                if($checkreset){
                    $resetpass = true;
                    $reset = [
                        'id'=>$user_id,
                        'code'=>$code
                    ];
                } else {
                    $this->container->flash->addMessage('error','Не удалось изменить пароль, попробуйте получить новый код восстановления.');
                    return $response->withRedirect($this->router->pathFor('auth.resetpass'));
                }
            }
        }
        $this->setViewDataArray([
            'title' => 'Восстановление пароля',
            'form' => $form_data,
            'resetpass'=>$resetpass,
            'reset'=>$reset,
            'errors' => $form_errors,
            'scripts' => $this->assets['home']['scripts'] ?? [],
            'styles' => $this->assets['home']['styles'] ?? [],
        ]);
        $this->setInnerTemplate('auth/resetpass.twig');
        return $this->render($response);
    }
}
