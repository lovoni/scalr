'''
Created on Aug 29, 2010

@author: marat
@author: spike
'''
import socket
import os
import time
from scalarizr.util import system2, PopenError
from scalarizr.util.filetool import read_file
import re

_services  = dict()
_instances = dict()


# TODO: error codes not used 
class InitdError(BaseException):
	GENERIC_ERR = 1
	INVALID_ARG = 2
	UNIMPLEMENTED = 3
	INSUFFICIENT_PRIVILEGE = 4
	NOT_INSTALLED = 5
	NOT_CONFIGURED = 6
	NOT_RUNNING = 7
	
	@property
	def code(self):
		return len(self.args) > 1 and self.args[1] or None
	
	@property
	def message(self):
		return self.args[0]

class Status:
	RUNNING = 0
	DEAD_PID_FILE_EXISTS = 1
	DEAD_VAR_LOCK_EXISTS = 2
	NOT_RUNNING = 3
	UNKNOWN = 4

class InitScript(object):
	name = None
	pid_file = None
	lock_file = None
	
	def start(self):
		'''
		@raise InitdError: 
		'''
		pass
	
	def stop(self):
		'''
		@raise InitdError: 
		'''		
		pass
	
	def restart(self):
		'''
		@raise InitdError: 
		'''		
		pass

	def reload(self):
		'''
		@raise InitdError: 
		'''		
		pass

	def status(self):
		'''
		@return: Service status
		@rtype: scalarizr.util.initdv2.Status
		'''
		pass

	def configtest(self):
		"""
		@raise InitdError:
		"""
		pass

class SockParam:
	def __init__(self, port=None, family=socket.AF_INET, type=socket.SOCK_STREAM, conn_address=None, timeout=5):
		
		self.family = family
		self.type = type
		self.conn_address = (conn_address or '127.0.0.1', int(port))
		self.timeout = timeout

class ParametrizedInitScript(InitScript):
	name = None
	
	def __init__(self, name, initd_script, pid_file=None, lock_file=None, socks=None):
		if isinstance(initd_script, basestring) \
			and not os.access(initd_script, os.F_OK | os.X_OK):
			err = 'Cannot find %s init script at %s. Make sure that %s is installed' % (
					name, initd_script, name)
			raise InitdError(err)
		
		self.name = name		
		self.initd_script = initd_script
		self.pid_file = pid_file
		self.lock_file = lock_file
		self.socks = socks
		
		'''
		@param socks: list(SockParam)
		'''
		
	def _start_stop_reload(self, action):
		try:
			args = [self.initd_script] \
					if isinstance(self.initd_script, basestring) \
					else list(self.initd_script)
			args.append(action) 
			out, err, returncode = system2(args, close_fds=True, preexec_fn=os.setsid)
		except PopenError, e:
			#temporary fix for broken status() method in mysql
			if 'Job is already running' in e:
				pass
			else:
				raise InitdError("Popen failed with error %s" % (e,))
		
		if returncode:
			raise InitdError("Cannot %s %s. output= %s. %s" % (action, self.name, out, err), returncode)

		if self.socks and (action != "stop" and not (action == 'reload' and not self.running)):
			for sock in self.socks:
				wait_sock(sock)
			
#		if self.pid_file:
#			if (action == "start" or action == "restart") and not os.path.exists(self.pid_file):
#				raise InitdError("Cannot start %s. pid file %s doesn't exists" % (self.name, self.pid_file))
#			if action == "stop" and os.path.exists(self.pid_file):
#				raise InitdError("Cannot stop %s. pid file %s still exists" % (self.name, self.pid_file))	
			
		return True
	
	def start(self):
		return self._start_stop_reload('start')
	
	def stop(self):
		return self._start_stop_reload('stop')
	
	def restart(self):
		return self._start_stop_reload('restart')
	
	def reload(self):
		if not self.running:
			raise InitdError('Service "%s" is not running' % self.name, InitdError.NOT_RUNNING)
		return self._start_stop_reload('reload') 
	
	def status(self):
		if self.pid_file:
			if not os.path.exists(self.pid_file):
				return Status.NOT_RUNNING
			pid = read_file(self.pid_file).strip()
			if os.path.isfile('/proc/%s/status' % pid):
				try:
					fp = open('/proc/%s/status' % pid)
					status = fp.read()
				except:
					return Status.NOT_RUNNING
				finally:
					fp.close()
					
				if status:
					pid_state = re.search('State:\s+(?P<state>\w)', status).group('state')
					if pid_state in ('T', 'Z'):
						return Status.NOT_RUNNING
			else:
				return Status.NOT_RUNNING
		if self.socks:
			try:
				for sock in self.socks:
					timeout = sock.timeout
					sock.timeout = 1
					try:
						wait_sock(sock)
					finally:
						sock.timeout = timeout
			except InitdError:
				return Status.NOT_RUNNING
		
		return Status.RUNNING
	
	@property
	def running(self):
		return self.status() == Status.RUNNING

def explore(name, init_script_cls):
	_services[name] = init_script_cls

def lookup(name):
	'''
	Lookup init script object by service name
	'''
	if not _services.has_key(name):
		raise InitdError('No service has been explored with name %s ' % name)
	
	if not _instances.has_key(name):
		_instances[name] = _services[name]()
		
	return _instances[name]
	
def wait_sock(sock = None):
	if not isinstance(sock, SockParam):
		raise InitdError('Socks parameter must be instance of SockParam class')
	
	time_start = time.time()
	while time.time() - time_start < sock.timeout:
		try:
			s = socket.socket(sock.family, sock.type)			
			s.connect(sock.conn_address)
			s.shutdown(2)
			del s
			return
		except:
			time.sleep(1)
			pass
	raise InitdError ("Service unavailable after %d seconds of waiting" % sock.timeout)