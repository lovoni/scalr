u'''
Created on Jul 7, 2011

@author: shaitanich
'''

import os
import time
import glob
import shlex
import shutil
import logging

from M2Crypto import RSA

from scalarizr.libs.metaconf import Configuration
from scalarizr.util import disttool, cryptotool, firstmatched, wait_until
from scalarizr import config
from scalarizr.config import BuiltinBehaviours
from scalarizr.bus import bus
from scalarizr.util import initdv2, system2, PopenError
from scalarizr.util.filetool import read_file, write_file, rchown
from scalarizr.services import BaseService, BaseConfig, lazy


BEHAVIOUR = SERVICE_NAME = CNF_SECTION = BuiltinBehaviours.POSTGRESQL #TODO: remove extra

SU_EXEC = '/bin/su'
USERMOD = '/usr/sbin/usermod'
USERADD = '/usr/sbin/useradd'
OPENSSL = '/usr/bin/openssl'
SSH_KEYGEN = '/usr/bin/ssh-keygen'
PASSWD_FILE = '/etc/passwd'

PSQL_PATH = '/usr/bin/psql'
CREATEUSER = '/usr/bin/createuser'
CREATEDB = '/usr/bin/createdb'
PG_DUMP = '/usr/bin/pg_dump'

ROOT_USER 				= "scalr"

OPT_REPLICATION_MASTER  = "replication_master"

STORAGE_DATA_DIR 		= "data"
TRIGGER_NAME 			= "trigger"

				
class PgSQLInitScript(initdv2.ParametrizedInitScript):
	socket_file = None
	
	@lazy
	def __new__(cls, *args, **kws):
		obj = super(PgSQLInitScript, cls).__new__(cls, *args, **kws)
		cls.__init__(obj)
		return obj
			
	def __init__(self):
		initd_script = None
		if disttool.is_ubuntu() and disttool.version_info() >= (10, 4):
			initd_script = ('/usr/sbin/service', 'postgresql')
		else:
			initd_script = firstmatched(os.path.exists, ('/etc/init.d/postgresql-9.0', '/etc/init.d/postgresql'))
		initdv2.ParametrizedInitScript.__init__(self, name=SERVICE_NAME, 
				initd_script=initd_script)
		
	def status(self):
		p = PSQL()
		return initdv2.Status.RUNNING if p.test_connection() else initdv2.Status.NOT_RUNNING

	def stop(self, reason=None):
		initdv2.ParametrizedInitScript.stop(self)
	
	def restart(self, reason=None):
		initdv2.ParametrizedInitScript.restart(self)
	
	def reload(self, reason=None):
		initdv2.ParametrizedInitScript.restart(self)
		
	def start(self):
		initdv2.ParametrizedInitScript.start(self)
		timeout = 60
		wait_until(lambda: self.status() == initdv2.Status.RUNNING, sleep=1, timeout=timeout, 
				error_text="In %s seconds after start Redis state still isn't 'Running'" % timeout)
	
	
initdv2.explore(SERVICE_NAME, PgSQLInitScript)


class PostgreSql(BaseService):
	
	_objects = None
	_instance = None
	
	service = None
		
	def __new__(cls, *args, **kwargs):
		if not cls._instance:
			cls._instance = super(PostgreSql, cls).__new__(
								cls, *args, **kwargs)
		return cls._instance
	
	def __init__(self):
		self._objects = {}
		self.service = initdv2.lookup(SERVICE_NAME)
		self._logger = logging.getLogger(__name__)
		self._cnf = bus.cnf
	
	@property
	def version(self):
		try:
			path = glob.glob('/var/lib/p*sql/9.*')[0]
			ver = os.path.basename(path)
		except IndexError:
			ver = None
		return ver

	@property	
	def unified_etc_path(self):
		return '/etc/postgresql/%s/main' % self.version if float(self.version) else '9.0'
					
	@property
	def is_replication_master(self):
		value = self._cnf.rawini.get(CNF_SECTION, OPT_REPLICATION_MASTER)
		self._logger.debug('Got %s : %s' % (OPT_REPLICATION_MASTER, value))
		return True if int(value) else False

	
	def init_master(self, mpoint, slaves=None):
		self._init_service(mpoint)
		self.postgresql_conf.hot_standby = 'off'
		
		if slaves:
			self._logger.debug('Registering slave hosts: %s' % ' '.join(slaves))
			for host in slaves:
				self.register_slave(host, force_restart=False)
		self.service.start()
		
	def init_slave(self, mpoint, primary_ip, primary_port):
		self._init_service(mpoint)
		
		self.root_user.apply_public_ssh_key() 
		self.root_user.apply_private_ssh_key()
		
		self.postgresql_conf.hot_standby = 'on'
		self.recovery_conf.trigger_file = os.path.join(self.config_dir.path, TRIGGER_NAME)
		self.recovery_conf.standby_mode = 'on'
		
		self.change_primary(primary_ip, primary_port, self.root_user.name)
		self.service.start()
		
	def register_slave(self, slave_ip, force_restart=True):
		self.postgresql_conf.listen_addresses = '*'
		self.pg_hba_conf.add_standby_host(slave_ip, self.root_user.name)
		self.postgresql_conf.max_wal_senders += 1
		if force_restart:
			self.service.restart(reason='Registering slave', force=True)
		
	def change_primary(self, primary_ip, primary_port, username):
		self.recovery_conf.primary_conninfo = (primary_ip, primary_port, username)
	
	def unregister_slave(self, slave_ip):
		self.pg_hba_conf.delete_standby_host(slave_ip, self.root_user.name)
		self.service.restart(reason='Unregistering slave', force=True)

	def stop_replication(self):
		self.trigger_file.create()
		
	def start_replication(self):
		self.trigger_file.destroy()
	
	def create_user(self, name, password=None, sys_user_only=True):
		user = PgUser(name)	
		password = password or user.generate_password(20)
		user._create_system_user(password)
		return user	
	
	def create_pg_role(self, name, super=True):
		self.set_trusted_mode()
		user = PgUser(name)	
		self.service.start()
		user._create_pg_database()
		user._create_role(super)
		self.set_password_mode()
		return user			

	def set_trusted_mode(self):
		self.pg_hba_conf.set_trusted_access_mode()
		#Temporary we need to force restart the service 
		self.service.restart(reason='Applying trusted mode', force=True)
	
	def set_password_mode(self):
		self.pg_hba_conf.set_password_access_mode()
		#Temporary we need to force restart the service 
		self.service.restart(reason='Applying password mode', force=True)

	def _init_service(self, mpoint):
		password = None 
		
		opt_pwd = '%s_password' % ROOT_USER
		if self._cnf.rawini.has_option(CNF_SECTION, opt_pwd):
			password = self._cnf.rawini.get(CNF_SECTION, opt_pwd)
			
		#this is highly temporary solution 
		if not password and self._cnf.rawini.has_option(CNF_SECTION, "root_password"):
			password = self._cnf.rawini.get(CNF_SECTION, "root_password")
		
		
		self.root_user = self.create_user(ROOT_USER, password)
		
		if not self.cluster_dir.is_initialized(mpoint):
			self.create_pg_role(ROOT_USER, super=True)
		
		self.service.stop()
		move_files = not self.cluster_dir.is_initialized(mpoint)
		self.postgresql_conf.data_directory = self.cluster_dir.move_to(mpoint, move_files)
		self.postgresql_conf.wal_level = 'hot_standby'
		self.postgresql_conf.max_wal_senders = 5
		self.postgresql_conf.wal_keep_segments = 32
		
		self.cluster_dir.clean()
		
		if disttool.is_centos():
			self.config_dir.move_to(self.unified_etc_path)
			make_symlinks(os.path.join(mpoint, STORAGE_DATA_DIR), self.unified_etc_path)
			self.postgresql_conf = PostgresqlConf.find(self.config_dir)
		

	def _set(self, key, obj):
		self._objects[key] = obj
		
	def _get(self, key, callback, *args, **kwargs):
		if not self._objects.has_key(key):
			self._set(key, callback(*args, **kwargs))
		return self._objects[key]
		
	def _get_config_dir(self):
		return self._get('config_dir', ConfigDir)
		
	def _set_config_dir(self, obj):
		self._set('config_dir', obj)
		
	def _get_postgresql_conf(self):
		return self._get('postgresql_conf', PostgresqlConf.find, self.config_dir)
	
	def _set_postgresql_conf(self, obj):
		self._set('postgresql_conf', obj)
	
	def _get_cluster_dir(self):
		return self._get('cluster_dir', ClusterDir.find, self.postgresql_conf)
		
	def _set_cluster_dir(self, obj):
		self._set('cluster_dir', obj)
			
	def _get_pg_hba_conf(self):
		return self._get('pg_hba_conf', PgHbaConf.find, self.config_dir)
		
	def _set_pg_hba_conf(self, obj):
		self._set('pg_hba_conf', obj)
		
	def _get_recovery_conf(self):
		return self._get('recovery_conf', RecoveryConf.find, self.cluster_dir)
	
	def _set_recovery_conf(self, obj):
		self._set('recovery_conf', obj)
	
	def _get_pid_file(self):
		return self._get('pid_file', PidFile.find, self.postgresql_conf)
	
	def _set_pid_file(self, obj):
		self._set('pid_file', obj)
		
	def _get_trigger_file(self):
		return self._get('trigger_file', Trigger.find, self.recovery_conf)
	
	def _set_trigger_file(self, obj):
		self._set('trigger_file', obj)
	
	def _get_root_user(self):
		key = 'root_user'
		if not self._objects.has_key(key):
			self._objects[key] = PgUser(ROOT_USER)
		return self._objects[key]
	
	def _set_root_user(self, user):
		self._set('root_user', user)
	
	root_user = property(_get_root_user, _set_root_user)
	config_dir = property(_get_config_dir, _set_config_dir)
	cluster_dir = property(_get_cluster_dir, _set_cluster_dir)
	postgresql_conf = property(_get_postgresql_conf, _set_postgresql_conf)
	pg_hba_conf = property(_get_pg_hba_conf, _set_pg_hba_conf)
	recovery_conf = property(_get_recovery_conf, _set_recovery_conf)
	pid_file = property(_get_pid_file, _set_pid_file)
	trigger_file = property(_get_trigger_file, _set_trigger_file)

	
postgresql = PostgreSql()

	
class PgUser(object):
	name = None
	psql = None
	
	public_key_path = None
	private_key_path = None
	opt_user_password = None

	def __init__(self, name, group='postgres'):
		self._logger = logging.getLogger(__name__)
		self._cnf = bus.cnf
			
		self.public_key_path = self._cnf.key_path('%s_public_key.pem' % name)
		self.private_key_path = self._cnf.key_path('%s_private_key.pem' % name)
		self.opt_user_password = '%s_password' % name
		
		self.name = name
		self.group = group
		self.psql = PSQL()
		
	def exists(self):
		return self._is_system_user_exist and self._is_role_exist and self._is_pg_database_exist
		
	@property
	def password(self):
		return self._cnf.rawini.get(CNF_SECTION, self.opt_user_password)
		
	def generate_password(self, length=20):
		return cryptotool.pwgen(length)

	def change_password(self, new_pass=None):
		new_pass = new_pass or self.generate_password()
		self._logger.debug('Changing password of system user %s to %s' % (self.name, new_pass)) 
		out, err, retcode = system2([OPENSSL, 'passwd', '-1', new_pass])
		shadow_password = out.strip()
		if retcode != 0:
			self._logger.error('Error creating hash for ' + self.name)
		if err:
			self._logger.error(err)
		
		r = system2([USERMOD, '-p', '-1', shadow_password, self.name])[2]
		if r != 0:
			self._logger.error('Error changing password for ' + self.name)	
		
		#change password in privated/pgsql.ini
		self.store_password(new_pass)
		
		return new_pass
	
	def check_password(self, password=None):
		#TODO: check (password or self.password), raise ValueError
		pass

	def store_password(self, password):
		self._cnf.update_ini(BEHAVIOUR, {CNF_SECTION: {self.opt_user_password:password}})	

		
	def store_keys(self, pub_key=None, pvt_key=None):
		'''
		@String pub_key, pvt_key
		'''
		if pub_key:
			self._store_key(pub_key, private=False)
		if pvt_key:
			self._store_key(pvt_key, private=True)
		
	def generate_private_ssh_key(self, key_length=1024):
		public_exponent = 65337
		key = RSA.gen_key(key_length, public_exponent)
		key.save_key(self.private_key_path, cipher=None)
		os.chmod(self.private_key_path, 0400)
		
	def extract_public_ssh_key(self):
		if not os.path.exists(self.private_key_path):
			raise Exception('Private key file %s does not exist.' % self.private_key_path)
		args = shlex.split('%s -y -f' % SSH_KEYGEN)
		args.append(self.private_key_path)
		out, err, retcode = system2(args)
		if err:
			self._logger.error('Failed to extract public key from %s : %s' % (self.private_key_path, err))
		if retcode != 0:
			raise Exception("Error handling would be nice, eh?")
		return out.strip()		
	
	def apply_public_ssh_key(self, source_path=None):
		source_path = source_path or self.public_key_path 
		if not os.path.exists(self.ssh_dir):
			os.makedirs(self.ssh_dir)
			rchown(self.name, self.ssh_dir)
		
		pub_key = read_file(source_path,logger=self._logger)
		path = os.path.join(self.ssh_dir, 'authorized_keys')
		keys = read_file(path,logger=self._logger) if os.path.exists(path) else ''
		
		if not keys or not pub_key in keys:
			write_file(path, data='\n%s %s\n' % (pub_key, self.name), mode='a', logger=self._logger)
			rchown(self.name, path)
			
	def apply_private_ssh_key(self,source_path=None):
		source_path = source_path or self.private_key_path
		if not os.path.exists(source_path):
			self._logger.error('Cannot apply private ssh key: source %s not found' % source_path)
		else:
			if not os.path.exists(self.ssh_dir):
				os.makedirs(self.ssh_dir)
				rchown(self.name, self.ssh_dir)
				
			dst = os.path.join(self.ssh_dir, 'id_rsa')
			shutil.copyfile(source_path, dst)
			os.chmod(dst, 0400)
			rchown(self.name, dst)
			
	
	@property
	def private_key(self):
		if not os.path.exists(self.private_key_path):
			self.generate_private_ssh_key()
			self.apply_private_ssh_key()
		return read_file(self.private_key_path, logger=self._logger)
	
	@property
	def public_key(self):
		if not os.path.exists(self.public_key_path):
			key = self.extract_public_ssh_key()
			write_file(self.public_key_path, key, logger=self._logger)
			self.apply_public_ssh_key()
		return read_file(self.public_key_path, logger=self._logger)
		
	@property
	def homedir(self):
		for line in open('/etc/passwd'):
			if line.startswith(self.name):
				return line.split(':')[-2]
		return None
	
	@property
	def ssh_dir(self):
		return os.path.join(self.homedir, '.ssh')

	@property
	def _is_role_exist(self):
		return self.name in self.psql.list_pg_roles()
	
	@property
	def _is_pg_database_exist(self):
		return self.name in self.psql.list_pg_databases()
	
	@property
	def _is_system_user_exist(self):
		file = open(PASSWD_FILE, 'r')
		return -1 != file.read().find(self.name)

	def _create_role(self, super=True):
		if self._is_role_exist:
			self._logger.debug('Cannot create role: role %s already exists' % self.name)
		else:
			self._logger.debug('Creating role %s' % self.name)
			try:
				out = system2([SU_EXEC, '-', self.group, '-c', '%s -s %s' % (CREATEUSER, self.name)])[0]
				self._logger.debug(out or 'Role %s has been successfully created.' % self.name)
			except PopenError, e:
				self._logger.error('Unable to create role %s: %s' % (self.name, e))
				raise
		
	def _create_pg_database(self):
		if self._is_pg_database_exist:
			self._logger.debug('Cannot create db: database %s already exists' % self.name)
		else:
			self._logger.debug('Creating db %s' % self.name)
			try:
				out = system2([SU_EXEC, '-', self.group, '-c', '%s %s' % (CREATEDB,self.name)])[0]
				self._logger.debug(out or 'DB %s has been successfully created.' % self.name)
			except PopenError, e:
				self._logger.error('Unable to create db %s: %s' % (self.name, e))
				raise
	
	def _create_system_user(self, password):
		if self._is_system_user_exist:
			self._logger.debug('Cannot create system user: user %s already exists' % self.name)
			#TODO: check password
		else:
			try:
				out = system2([USERADD, '-m', '-g', self.group, '-p', password, self.name])[0]
				if out: self._logger.debug(out)
				self._logger.debug('Creating system user %s' % self.name)	
			except PopenError, e:
				self._logger.error('Unable to create system user %s: %s' % (self.name, e))
				raise
		self.store_password(password)
	
	def _store_key(self, key_str, private=True):
		write_file(self.private_key_path if private else self.public_key_path, data=key_str, logger=self._logger)
		
		
class PSQL(object):
	path = PSQL_PATH
	user = None
	
	def __init__(self, user='postgres'):	
		self.user = user
		self._logger = logging.getLogger(__name__)
		
	def test_connection(self):
		self._logger.debug('Checking PostgreSQL service status')
		
		def test_recursive(attempt):
			try:
				self.execute('SELECT 1;')
			except PopenError, e:
				if 'could not connect to server' in str(e):
					return False
				elif 'the database system is starting up' in str(e):
					if not attempt:
						raise BaseException('Postgresql service stuck on starting up database system')
					time.sleep(5)
					return test_recursive(attempt-1)
			return True
		return test_recursive(6)
		
	def execute(self, query):
		try:
			out = system2([SU_EXEC, '-', self.user, '-c', '%s -c "%s"' % (self.path, query)], silent=True)[0]
			return out	
		except PopenError, e:
			self._logger.error('Unable to execute query %s from user %s: %s' % (query, self.user, e))
			raise		

	def list_pg_roles(self):
		out = self.execute('SELECT rolname FROM pg_roles;')
		roles = out.split()[2:-2]
		return roles
	
	def list_pg_databases(self):
		out = self.execute('SELECT datname FROM pg_database where not datistemplate;')
		roles = out.split()[2:-2]
		return roles	
	
	def delete_pg_role(self, name):
		out = self.execute('DROP ROLE IF EXISTS %s;' % name)
		self._logger.debug(out)

	def delete_pg_database(self, name):
		out = self.execute('DROP DATABASE IF EXISTS %s;' % name)
		self._logger.debug(out)
		
	def start_backup(self):
		try:
			out = self.execute("SELECT pg_start_backup('label', true);")
			self._logger.debug(out)
		except PopenError, e:
			self._logger.warning('Cannot start backup: %s' % e)

	def stop_backup(self):
		try:
			out = self.execute("SELECT pg_stop_backup();")
			self._logger.debug(out)
		except PopenError, e:
			self._logger.warning('Cannot stop backup: %s' % e)
					
	
class ClusterDir(object):
	base_path = glob.glob('/var/lib/p*sql/9.*/')[0]
	default_centos_path = os.path.join(base_path, 'data')
	default_ubuntu_path = os.path.join(base_path, 'main')
	
	def __init__(self, path=None, user = "postgres"):
		self.path = path
		self.user = user
		self._logger = logging.getLogger(__name__)
		
	@classmethod
	def find(cls, postgresql_conf):
		path = postgresql_conf.data_directory
		if not path:
			path = cls.default_ubuntu_path if disttool.is_ubuntu() else cls.default_centos_path
		return cls(path)

	def move_to(self, dst, move_files=True):
		new_cluster_dir = os.path.join(dst, STORAGE_DATA_DIR)
		
		if not os.path.exists(dst):
			self._logger.debug('Creating directory structure for postgresql cluster: %s' % dst)
			os.makedirs(dst)
		
		if move_files and os.path.exists(self.path):
			self._logger.debug("copying cluster files from %s into %s" % (self.path, new_cluster_dir))
			shutil.copytree(self.path, new_cluster_dir)	
		self._logger.debug("changing directory owner to %s" % self.user)	
		rchown(self.user, dst)
		
		self._logger.debug("Changing postgres user`s home directory")
		if disttool.is_centos():
			#looks like ubuntu doesn`t need this
			system2([USERMOD, '-d', new_cluster_dir, self.user]) 
			
		self.path = new_cluster_dir
	
		return new_cluster_dir
	
	def clean(self):
		fnames = ('recovery.conf','recovery.done','postmaster.pid')
		for fname in fnames:
			exclude = os.path.join(self.path, fname)
			if os.path.exists(exclude):
				self._logger.debug('Deliting file: %s' % exclude)
				os.remove(exclude)
	
	def is_initialized(self, path):
		# are the pgsql files already in place? 
		return os.path.exists(path) and STORAGE_DATA_DIR in os.listdir(path)


class ConfigDir(object):
	
	path = None
	user = None
	sysconf_path = '/etc/sysconfig/pgsql/postgresql-9.0'
	
	def __init__(self, path=None, user = "postgres"):
		self._logger = logging.getLogger(__name__)
		self.path = path or self.find_path()
		self.user = user
	
	def find_path(self):
		path = self.get_sysconfig_pgdata()
		if path:
			return path
		l = glob.glob('/etc/postgresql/9.*/main') if disttool.is_ubuntu() else glob.glob('/var/lib/p*sql/9.*/data')
		return l[0] if l else None
	
	def move_to(self, dst):
		if not os.path.exists(dst):
			self._logger.debug("creating %s" % dst)
			os.makedirs(dst)
		
		for config in ['postgresql.conf', 'pg_ident.conf', 'pg_hba.conf']:
			old_config = os.path.join(self.path, config)
			new_config = os.path.join(dst, config)
			if os.path.exists(old_config):
				self._logger.debug('Moving %s' % config)
				shutil.move(old_config, new_config)
			elif os.path.exists(new_config):
				self._logger.debug('%s is already in place. Skipping.' % config)
			else:
				raise BaseException('Postgresql config file not found: %s' % old_config)
			rchown(self.user, new_config)

		#the following block needs revision
		
		#self._make_symlinks(dst)
		self._patch_sysconfig(dst)
		
		self.path = dst
		
		self._logger.debug("configuring pid")
		conf = PostgresqlConf.find(self)
		conf.pid_file = os.path.join(dst, 'postmaster.pid')

	def _patch_sysconfig(self, config_dir):
		if config_dir == self.get_sysconfig_pgdata():
			self._logger.debug('sysconfig file already rewrites PGDATA. Skipping.')
		else:
			self.set_sysconfig_pgdata(config_dir)
	
	def set_sysconfig_pgdata(self, pgdata):
		self._logger.debug("rewriting PGDATA path in sysconfig")
		dir = os.path.dirname(self.sysconf_path)
		if not os.path.exists(dir):
			#ubuntu 11.10 has no sysconfig dir in etc
			os.makedirs(dir)
		file = open(self.sysconf_path, 'w')
		file.write('PGDATA=%s' % pgdata)
		file.close()
		
	def get_sysconfig_pgdata(self):
		pgdata = None
		if os.path.exists(self.sysconf_path):
			s = open(self.sysconf_path, 'r').readline().strip()
			if s and len(s)>7:
				pgdata = s[7:]
			else: 
				self._logger.debug('sysconfig has no PGDATA')
		return pgdata


class PidFile(object):
	path = None
	
	def __init__(self, path):
		self.path = path
		
	@classmethod
	def find(cls, postgresql_conf):
		return cls(postgresql_conf.pid_file)	
	
	@property	
	def proc_id(self):
		return open(self.path, 'r').readline().strip() if os.path.exists(self.path) else None


class Trigger(object):
	
	path = None
	
	def __init__(self, path):
		self.path = path
		self._logger = logging.getLogger(__name__)
		
	@classmethod
	def find(cls, recovery_conf):
		return cls(recovery_conf.trigger_file)
	
	def create(self):
		if not self.exists():
			null = ''
			write_file(self.path, null, 'w', logger=self._logger)
		
	def destroy(self):
		if self.exists():
			os.remove(self.path)
		
	def exists(self):
		return os.path.exists(self.path)
	
			
class BasePGConfig(BaseConfig):
	config_type = 'pgsql'

class PostgresqlConf(BasePGConfig):

	config_name = 'postgresql.conf'

	def _get_pid_file_path(self):
		return self.get('external_pid_file')
	
	def _set_pid_file_path(self, path):
		self.set('external_pid_file', path)
		if not os.path.exists(path):
			self._logger.debug('pid file does not exist')
	
	def _get_data_directory(self):
		return self.get('data_directory')
	
	def _set_data_directory(self, path):
		self.set_path_type_option('data_directory', path)
	
	def _get_wal_level(self):
		return self.get('wal_level')
	
	def _set_wal_level(self, level):
		self.set('wal_level', level)
	
	def _get_max_wal_senders(self):
			return self.get_numeric_option('max_wal_senders')
	
	def _set_max_wal_senders(self, number):
		self.set_numeric_option('max_wal_senders', number)
	
	def _get_wal_keep_segments(self):
		return self.get_numeric_option('wal_keep_segments')
	
	def _set_wal_keep_segments(self, number):
		self.set_numeric_option('wal_keep_segments', number)
		
	def _get_listen_addresses(self):
		return self.get('listen_addresses')
	
	def _set_listen_addresses(self, addresses='*'):
		self.set('listen_addresses', addresses)
	
	def _get_hot_standby(self):
		return self.get('hot_standby')
	
	def _set_hot_standby(self, mode):
		#must bee boolean and default is 'off'
		self.set('hot_standby', mode)
		
	pid_file = property(_get_pid_file_path, _set_pid_file_path)
	data_directory = property(_get_data_directory, _set_data_directory)
	wal_level = property(_get_wal_level, _set_wal_level)
	max_wal_senders = property(_get_max_wal_senders, _set_max_wal_senders)
	wal_keep_segments = property(_get_wal_keep_segments, _set_wal_keep_segments)
	listen_addresses = property(_get_listen_addresses, _set_listen_addresses)
	hot_standby = property(_get_hot_standby, _set_hot_standby)
	
	max_wal_senders_default = 5
	wal_keep_segments_default = 32


	
class RecoveryConf(BasePGConfig):
	
	config_name = 'recovery.conf'
	
	def _get_standby_mode(self):
		return self.get('standby_mode')
	
	def _set_standby_mode(self, mode):
		self.set('standby_mode', mode)
	
	def _get_primary_conninfo(self):
		info = self.get('primary_conninfo')
		return tuple([raw.split('=')[1].strip() if len(raw.split('=')) == 2 else '' for raw in info.split()])
		
	def _set_primary_conninfo(self, info_tuple):
		#need to check first
		host, port, user = info_tuple
		self.set('primary_conninfo', "host=%s port=%s user=%s" % (host,port,user))
		
	def _get_trigger_file(self):
		return self.get('trigger_file')
	
	def _set_trigger_file(self, path):
		self.set('trigger_file', path)	
	
	standby_mode = property(_get_standby_mode, _set_standby_mode)
	primary_conninfo = property(_get_primary_conninfo, _set_primary_conninfo)
	trigger_file = property(_get_trigger_file, _set_trigger_file)
	
	@classmethod
	def find(cls, cluster_dir):
		return cls(os.path.join(cluster_dir.path, cls.config_name))

	
class PgHbaRecord(object):
	'''
	A record can have one of the seven formats
	
	local      database  user  auth-method  [auth-options]
	host       database  user  address  auth-method  [auth-options]
	hostssl    database  user  address  auth-method  [auth-options]
	hostnossl  database  user  address  auth-method  [auth-options]
	host       database  user  IP-address  IP-mask  auth-method  [auth-options]
	hostssl    database  user  IP-address  IP-mask  auth-method  [auth-options]
	hostnossl  database  user  IP-address  IP-mask  auth-method  [auth-options]
	'''
	host_types = ['local', 'host', 'hostssl','hostnossl']
	auth_methods = ['trust','reject','md5','password', 'gss',
				'sspi', 'krb5', 'ident', 'peer', 'ldap',
				'radius', 'cert', 'pam']
	
	def __init__(self, host='local', database='all', user='all', auth_method='trust', address=None, ip=None, mask=None, auth_options=None):		
		self.host = host
		self.database = database
		self.user = user
		self.auth_method = auth_method
		self.auth_options = auth_options
		self.address = address 
		self.ip = ip
		self.mask = mask		
	
	@classmethod
	def from_string(cls, entry):
		attrs = entry.split()
		if len(attrs) < 4:
			raise ParseError('Cannot parse pg_hba.conf entry: %s. Entry must contain more than 4 values' % entry)

		host = attrs[0]
		database = attrs[1]
		user = attrs[2]

		if host not in cls.host_types:
			raise ParseError('Cannot parse pg_hba.conf entry: %s. Unknown host type' % entry)
		
		last_attrs = attrs[3:]
		for method in cls.auth_methods:
			if method in last_attrs:
				
				auth_method = method
				
				index = last_attrs.index(method)
				host_info = last_attrs[:index]
				address = host_info[0] if len(host_info) == 1 else None
				(ip, mask) = (host_info[0], host_info[1]) if len(host_info) == 2 else (None,None)

				if host=='local' and (address or ip):
					raise ParseError('Cannot parse pg_hba.conf entry: %s. Address cannot be set when host is "local"' % entry)
				elif address and (ip or mask): 
					raise ParseError('Cannot parse pg_hba.conf entry: %s. Cannot set adress along with ip and mask' % entry)				
				auth_options = ' '.join(last_attrs[index+1:]) if len(last_attrs[index+1:]) else None
				
				break
		else:
			raise ParseError('Cannot parse pg_hba.conf entry: %s. No auth method found' % entry)
		return PgHbaRecord(host, database, user, auth_method, address, ip, mask, auth_options)
	
	def is_similar_to(self, other):
		return 	self.host == other.host and \
		self.database == other.database and \
		self.user == other.user and \
		self.address == other.address and \
		self.ip == other.ip and \
		self.mask == other.mask	
	
	def __eq__(self, other):
		return self.is_similar_to(other) and \
		self.auth_method == other.auth_method and \
		self.auth_options == other.auth_options	
			
	def __repr__(self):
		line = '%s\t%s\t%s' % (self.host, self.database, self.user)
		
		if self.address: line += '\t%s' % self.address
		else:
			if self.ip: line += '\t%s' % self.ip
			if self.mask: line += '\t%s' % self.mask
		
		line +=  '\t%s' % self.auth_method
		if self.auth_options: line += '\t%s' % self.auth_options
			
		return line	
	
		
class PgHbaConf(Configuration):
	
	config_name = 'pg_hba.conf'
	path = None
	trusted_mode = PgHbaRecord('local', 'all', 'postgres', auth_method = 'trust')
	password_mode = PgHbaRecord('local', 'all', 'postgres', auth_method = 'password')
	
	def __init__(self, config_dir_path):
		self.config_dir_path = config_dir_path
		self.path = os.path.join(self.config_name, config_dir_path)
		self._logger = logging.getLogger(__name__)

	@classmethod
	def find(cls, config_dir):
		return cls(os.path.join(config_dir.path, cls.config_name))
	
	def add_record(self, record):
		text = read_file(self.path) or ''
		for line in text.splitlines():
			if  line.strip() and not line.strip().startswith('#') and PgHbaRecord.from_string(line) == record:
				#already in file
				return
		write_file(self.path, str(record)+'\n' if text.endswith('\n') else '\n'+str(record)+'\n', 'a')
			
	def delete_record(self, record):
		lines = []
		changed = False
		text = read_file(self.path) or ''
		for line in text.splitlines():
			if line.strip() and not line.strip().startswith('#') and PgHbaRecord.from_string(line) == record:
				changed = True
				continue
			lines.append(line)
		if changed:
			write_file(self.path, '\n'.join(lines))
	
	def add_standby_host(self, ip, user='postgres'):
		record = self._make_standby_record(ip, user)
		self.add_record(record)

	def delete_standby_host(self, ip, user='postgres'):
		record = self._make_standby_record(ip, user)
		self.delete_record(record)
	
	def set_trusted_access_mode(self):
		self.delete_record(self.password_mode)
		self.add_record(self.trusted_mode)
	
	def set_password_access_mode(self):
		self.delete_record(self.trusted_mode)
		self.add_record(self.password_mode)
	
	def _make_standby_record(self,ip, user='postgres'):
		return PgHbaRecord('host','replication', user=user,address='%s/32'%ip, auth_method='trust')
	
	
class ParseError(BaseException):
	pass

		
def make_symlinks(source_dir, dst_dir, username='postgres'):
	#Vital hack for getting CentOS init script to work
	for obj in ['base', 'PG_VERSION', 'postmaster.pid']:
		
		src = os.path.join(source_dir, obj)
		dst = os.path.join(dst_dir, obj) 
		
		if os.path.islink(dst):
			os.unlink(dst)
		elif os.path.exists(dst):
			shutil.rmtree(dst)
			
		os.symlink(src, dst)
		
		if os.path.exists(src):
			rchown(username, dst)	
				
								