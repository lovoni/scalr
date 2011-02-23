from __future__ import with_statement
import socket
import os, re
import logging
import threading
import weakref
import time
import sys
import signal
import string

from scalarizr.bus import bus

class UtilError(BaseException):
	pass


class LocalObject:
	def __init__(self, creator, pool_size=10):
		self._logger = logging.getLogger(__name__)
		self._creator = creator		
		self._object = threading.local()
		
		self._all_conns = set()
		self.size = pool_size
	
	def do_create(self):
		return self._creator()
	
	def get(self):
		try:
			o = self._object.current
			if o():
				return o()
			else:
				self._logger.debug("Current weakref is empty")
		except AttributeError, e:
			self._logger.debug("Caught: %s", e)
		
		self._logger.debug("Creating new object...")
		o = self.do_create()
		self._logger.debug("Created %s", o)
		self._object.current = weakref.ref(o)
		self._logger.debug("Added weakref %s", self._object.current)
		self._all_conns.add(o)
		if len(self._all_conns) > self.size:
			self.cleanup()
		return o
	
	def cleanup(self):
		for conn in list(self._all_conns):
			self._all_conns.discard(conn)
			if len(self._all_conns) <= self.size:
				return
	
class SqliteLocalObject(LocalObject):
	def do_create(self):
		return _SqliteConnection(self, self._creator)
	
class _SqliteConnection(object):
	_conn = None
	#_lo = None
	_creator = None
	
	def __init__(self, lo, creator):
		#self._lo = lo
		self._creator = creator
	
	def get_connection(self):
		if not self._conn:
			self._conn = self._creator()
		return self._conn
	
def cached(f, cache={}):
	'''
	Decorator
	'''
	def g(*args, **kwargs):
		key = (f, tuple(args), frozenset(kwargs.items()))
		if key not in cache:
			cache[key] = f(*args, **kwargs)
		return cache[key]
	return g	

def firstmatched(function, sequence, default=None):
	for s in sequence:
		if function(s):
			return s
			break
	else:
		return default	

def daemonize():
	# First fork
	pid = os.fork()
	if pid > 0:
		sys.exit(0) 	
	
	os.chdir("/")
	os.setsid()
	os.umask(0)
	
	# Second fork
	pid = os.fork()
	if pid > 0:
		sys.exit(0)
		
	# Redirect standard file descriptors
	sys.stdout.flush()
	sys.stderr.flush()
	si = file(os.devnull, 'r')
	so = file(os.devnull, 'a+')
	se = file(os.devnull, 'a+', 0)
	os.dup2(si.fileno(), sys.stdin.fileno())
	os.dup2(so.fileno(), sys.stdout.fileno())
	os.dup2(se.fileno(), sys.stderr.fileno())
	
	
def system(args, shell=True):
	import subprocess
	logger = logging.getLogger(__name__)
	logger.debug("system: %s", args)
	p = subprocess.Popen(args, shell=shell, env={'LANG' : 'en_US'}, 
			stdout=subprocess.PIPE, stderr=subprocess.PIPE)
	out, err = p.communicate()
	if out:
		logger.debug("stdout: " + out)
	if err:
		logger.debug("stderr: " + err)
	return out, err, p.returncode

class PopenError(BaseException):
	
	def __str__(self):
		if len(self.args) >= 5:
			args = [self.error_text or '']
			args += [self.proc_args[0] if hasattr(self.proc_args, '__iter__') else self.proc_args.split(' ')[0]]
			args += [self.returncode, self.out, self.err, self.proc_args]

			ret = '%s %s (code: %s) <out>: %s <err>: %s <args>: %s' % tuple(args)
			return ret.strip()
		else:
			return self.error_text
	
	@property
	def error_text(self):
		return self.args[0]
	
	@property
	def out(self):
		return self.args[1]
	
	@property
	def err(self):
		return self.args[2]

	@property
	def returncode(self):
		return self.args[3]
	
	@property
	def proc_args(self):
		return self.args[4]

def system2(*popenargs, **kwargs):
	import subprocess, cStringIO
	
	logger 		= kwargs.get('logger', logging.getLogger(__name__))
	warn_stderr = kwargs.get('warn_stderr')
	raise_exc   = kwargs.get('raise_exc', kwargs.get('raise_error',  True))
	ExcClass 	= kwargs.get('exc_class', PopenError)
	error_text 	= kwargs.get('error_text')
	input 		= None
	
	if kwargs.get('err2out'):
		# Redirect stderr -> stdout
		kwargs['stderr'] = subprocess.STDOUT
		
	if not 'stdout' in kwargs:
		# Capture stdout
		kwargs['stdout'] = subprocess.PIPE
		
	if not 'stderr' in kwargs:
		# Capture stderr
		kwargs['stderr'] = subprocess.PIPE
		
	if isinstance(kwargs.get('stdin'),  basestring):
		# Pass string into stdin
		input = kwargs['stdin']
		kwargs['stdin'] = subprocess.PIPE
		
	if len(popenargs) > 0 and hasattr(popenargs[0], '__iter__'):
		# Cast arguments to str
		popenargs = list(popenargs)
		popenargs[0] = tuple('%s' % arg for arg in popenargs[0])
		
	if kwargs.get('shell'):
		# Set en_US locale
		if not 'env' in kwargs:
			kwargs['env'] = {}
		kwargs['env']['LANG'] = 'en_US'
		
	for k in ('logger', 'err2out', 'warn_stderr', 'raise_exc', 'raise_error', 'exc_class', 'error_text'):
		try:
			del kwargs[k]
		except KeyError:
			pass
		
	logger.debug('system: %s' % (popenargs[0],))
	p = subprocess.Popen(*popenargs, **kwargs)
	out, err = p.communicate(input=input)
	
	if out:
		logger.debug('stdout: ' + out)
	if err:
		logger.log(logging.WARN if warn_stderr else logging.DEBUG, 'stderr: ' + err)
	if p.returncode and raise_exc:
		raise ExcClass(error_text, out.strip(), err and err.strip() or '', p.returncode, popenargs[0])

	return out, err, p.returncode


def wait_until(target, args=None, kwargs=None, sleep=5, logger=None, time_until=None, timeout=None):
	args = args or ()
	kwargs = kwargs or {}
	if timeout:
		time_until = time.time() + timeout
	while not target(*args, **kwargs):
		if time_until and time.time() >= time_until:
			raise BaseException('Time until: %d reached' % time_until)
		if logger:
			logger.debug("Wait %.2f seconds before the next attempt", sleep)
		time.sleep(sleep)


def xml_strip(el):
	for child in list(el.childNodes):
		if child.nodeType==child.TEXT_NODE and child.nodeValue.strip() == '':
			el.removeChild(child)
		else:
			xml_strip(child)
	return el	

def url_replace_hostname(url, newhostname):
	import urlparse	
	r = url if isinstance(url, tuple) else urlparse.urlparse(url)
	r2 = list(r)
	r2[1] = newhostname
	if r.port:
		r2[1] += ":" + str(r.port)
	return urlparse.urlunparse(r2)
	


def read_shebang(path=None, script=None):
	if path:
		file = first_line = None
		try:
			file = open(path, 'r')
			first_line = file.readline()
		finally:
			if file:
				file.close()
	elif script:
		if not isinstance(script, basestring):
			raise ValueError('argument `script` should be a basestring subclass')
		eol_index = script.find(os.linesep)
		first_line = eol_index != -1 and script[0:eol_index] or script
	else:
		raise ValueError('one of arguments `path` or `script` should be passed')

	shebang = re.search(re.compile('^#!(\S+.+)'), first_line)
	if shebang:
		return shebang.group(1)
	return None

def parse_size(size):
	"""
	Read string like 10K, 12M, 1014B and return size in bytes
	"""
	ret = str(size)
	dim = ret[-1]		
	ret = float(ret[0:-1])
	if dim.lower() == "b":
		pass		
	elif dim.lower() == "k":
		ret *= 1024
	elif dim.lower() == "m":
		ret *= 1048576	
	
	return ret
	
def format_size(size, precision=2):
	"""
	Format size in Bytes, KBytes and MBytes
	"""
	ret = float(size)
	dim = "B"
	if ret > 1000:
		ret = ret/1000
		dim = "K"
	if ret > 1000:
		ret = ret/1000
		dim = "M"
		
	s = "%."+str(precision)+"f%s"
	return s % (ret, dim)	

def backup_file(filename):
	import shutil
	logger = logging.getLogger(__name__)
	max_backups = 50
	
	for i in range(0, max_backups):
		bkname = '%s.bak.%s' % (filename, i)		
		if not os.path.exists(bkname):
			logger.debug('Backuping %s to %s', filename, bkname)
			shutil.copy(filename, bkname)
			return bkname
	raise UtilError("Max backups limit %d exceed for file %s" % (max_backups, filename))


def timethis(what):
	try:
		import time
	except ImportError:
		import timemodule as time
	from contextlib import contextmanager	
	
	@contextmanager
	def benchmark():
		start = time.time()
		yield
		end = time.time()
		print("%s : %0.3f seconds" % (what, end-start))
	if hasattr(what,"__call__"):
		def timed(*args,**kwargs):
			with benchmark():
				return what(*args,**kwargs)
		return timed
	else:
		return benchmark()


def split_ex(value, separator=",", allow_empty=False, ct=list):
	return ct(v.strip() 
			for v in value.split(separator) 
			if allow_empty or (not allow_empty and v)) if value else ct()


def get_free_devname():
	#[o..z]
	dev_list = os.listdir('/dev')
	for letter in string.ascii_lowercase[14:]:
		device = 'sd'+letter
		if not device in dev_list:
			return '/dev/'+device
		
def kill_childs(pid):
	ppid_re = re.compile('^PPid:\s*(?P<pid>\d+)\s*$', re.M)
	for process in os.listdir('/proc'):
		if not re.match('\d+', process):
			continue
		try:
			fp = open('/proc/' + process + '/status')
			process_info = fp.read()
			fp.close()
		except:
			pass
		
		Ppid_result = re.search(ppid_re, process_info)
		if not Ppid_result:
			continue
		ppid = Ppid_result.group('pid')
		if int(ppid) == pid:
			try:
				os.kill(int(process), signal.SIGKILL)
			except:
				pass
		

def ping_socket(host, port, exc_str=None):
	s = socket.socket()
	try:
		s.connect((host, port))
	except:
		raise Exception(exc_str or 'Service is not running: Port %s on %s closed.' % (port, host))
	
def port_in_use(port):
	s = socket.socket()
	try:
		s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)		
		s.bind(('0.0.0.0', port))
		return False
	except socket.error:
		return True
	finally:
		s.close()

		
class PeriodicalExecutor:
	_logger = None
	_tasks = None
	_lock = None
	_ex_thread = None
	_shutdown = None
	
	def __init__(self):
		self._logger = logging.getLogger(__name__ + '.PeriodicalExecutor')
		self._tasks = dict()
		self._ex_thread = threading.Thread(target=self._executor, name='PeriodicalExecutor')
		self._ex_thread.setDaemon(True)
		self._lock = threading.Lock()
	
	def start(self):
		self._shutdown = False		
		self._ex_thread.start()
		
	def shutdown(self):
		self._shutdown = True
		self._ex_thread.join(1)
	
	def add_task(self, fn, interval, title=None):
		self._lock.acquire()
		try:
			if fn in self._tasks:
				raise BaseException('Task %s already registered in executor with an interval %s minutes', 
					fn, self._tasks[fn])
			if interval <= 0:
				raise ValueError('interval should be > 0')
			self._tasks[fn] = dict(fn=fn, interval=interval, title=title, last_exec_time=0)
		finally:
			self._lock.release()
	
	def remove_task(self, fn):
		self._lock.acquire()
		try:
			if fn in self._tasks:
				del self._tasks[fn]
		finally:
			self._lock.release()
		
	def _tasks_to_execute(self):
		self._lock.acquire()		
		try:
			now = time.time()			
			return list(task for task in self._tasks.values()
					if now - task['last_exec_time'] > task['interval'])
		finally:
			self._lock.release()
		
	def _executor(self):
		while not self._shutdown:
			for task in self._tasks_to_execute():
				self._logger.debug('Executing task %s', task['title'] or task['fn'])
				try:
					task['last_exec_time'] = time.time()
					task['fn']()
				except (BaseException, Exception), e:
					self._logger.exception(e)
				if self._shutdown:
					break
			if not self._shutdown:
				time.sleep(1)
