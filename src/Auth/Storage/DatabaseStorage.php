<?php
namespace Cake\Auth\Storage;

use Cake\Network\Request;
use Cake\Network\Response;
use Cake\ORM\TableRegistry;

class DatabaseStorage implements StorageInterface{

	private $_user;
	private $_sessionId;
	private $_sessions;

	public function __construct(Request $request, Response $response){
		$this->_user = (object)[];
		$this->_user->clientIp = $request->clientIp();
		$this->_user->userAgent = $request->env("HTTP_USER_AGENT");
		$this->_user->userAgentHash = sha1($this->_user->userAgent);
		$this->_user->redirectUrl = null;
		$this->_user->data = null;

		$this->_sessions = TableRegistry::get("Sessions");
	}

	private function _getCurrentSession(){
		return $this->_sessions->find("all")->contain(["Users"])->where(["Sessions.id" => $this->_sessionId])->first();
	}

	private function _createSession(array $user){
		$session = $this->_sessions->newEntity();
		$session->userId = $user["id"];
		$session->userAgent = $this->_user->userAgent;
		$session->userAgentHash = $this->_user->userAgentHash;
		$session->ipv4 = $this->_user->clientIp;
		$session->timeLastActive = time();
		$session->isValid = 1;
		$session->user = $user;

		return $session;
	}

	public function read(){
		/* If there is no userData available, get it from the database */
		if(empty($this->_user->data)){
			/* If we know a sessionId, look for that session */
			if($this->_sessionId){
				$session = $this->_getCurrentSession();

				/* If there is a session with that Id, save and return the associated User */
				if(!empty($session)){
					$this->_user->data = $session->getUser();
				}
				else{
					/* If there is no session found, erase all session data */
					$this->_sessionId = null;
					$this->_user->data = null;
				}

				return $this->_user->data;
			}

			/* If we don't know the sessionId, look for a session based on userAgent and ip */
			else{
				$session = $this->_sessions
					->find("all")
					->contain("Users")
					->where([
								"userAgentHash" => $this->_user->userAgentHash,
								"ipv4" => $this->_user->clientIp,
							])
					->first();

				if(!empty($session)){
					$this->_sessionId = $session->id;
					return $this->_user->data = $session->getUser();
				}
				else{
					/* If there is no session found, erase all session data */
					$this->_sessionId = null;
					$this->_user->data = null;
				}

				return null;
			}
		}

		return $this->_user->data;
	}

	public function write($user){
		/* If we don't know the current session id, try to find it by userAgentHash and ip */
		if(!$this->_sessionId){
			$session = $this->_sessions
				->find("all")
				->where([
							"userAgentHash" => $this->_user->userAgentHash,
							"ipv4" => $this->_user->clientIp,
						])->first();

			if(empty($session)){
				$session = $this->_createSession($user);
			}
		}
		else{
			$session = $this->_getCurrentSession();
			$session->user = $user;
		}

		$this->_sessions->save($session);
	}

	public function delete(){
		/* If we have a session, remove it from the database */
		if($this->_sessionId){
			$session = $this->_getCurrentSession();
			$this->_sessions->delete($session);
		}
	}

	public function redirectUrl($url = null){
		if($url == null){
			return $this->_user->redirectUrl;
		}

		if($url === false){
			$this->_user->redirectURL = null;
			return null;
		}

		$this->_user->redirectUrl = $url;
		return null;
	}

}
