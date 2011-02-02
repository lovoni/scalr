
from scalarizr.config import BuiltinPlatforms
from scalarizr.platform import Platform, PlatformError
from scalarizr.storage.transfer import Transfer
from .storage import CFTransferProvider

from scalarizr.util import system2

import logging
import re
import os

from cloudservers import CloudServers 
import cloudfiles

Transfer.explore_provider(CFTransferProvider)

def _credentials(username=None, api_key=None):
	try:
		username = username or os.environ["CLOUD_SERVERS_USERNAME"]
		api_key = api_key or os.environ['CLOUD_SERVERS_API_KEY']
		return username, api_key
	except KeyError:
		raise PlatformError('Rackspace API credentials not defined')

def new_cloudserver_conn(username=None, api_key=None):
	return CloudServers(*_credentials(username, api_key))

def new_cloudfiles_conn(username=None, api_key=None, **kwargs):
	kwargs = kwargs or dict()
	if not 'servicenet' in kwargs:
		kwargs['servicenet'] = True
	return cloudfiles.Connection(*_credentials(username, api_key), **kwargs)

def get_platform():
	return RackspacePlatform()

class RackspacePlatform(Platform):
	name 			= BuiltinPlatforms.RACKSPACE
	
	_meta_url		= None
	_storage_url	= None
	_cdn_url		= None
	_storage_url	= None
	_auth_token		= None
	_id				= None
	_metadata		= None
	
	_private_ip = None
	_public_ip = None
	
	def __init__(self):
		self._logger = logging.getLogger(__name__)

	def get_private_ip(self):
		if not self._private_ip:
			self._private_ip = self._get_netiface_ip("eth1")
		return self._private_ip

	def get_public_ip(self):
		if not self._public_ip:
			self._public_ip = self._get_netiface_ip("eth0")
		return self._public_ip
	
	def _get_netiface_ip(self, iface=None):
		if not iface:
			raise PlatformError('You must specify interface name to retrieve ip address')
		if not hasattr(self, '_ip_re'):
			self._ip_re = re.compile('inet\s*addr:(?P<ip>[\d\.]+)', re.M)
			
		out = system2('/sbin/ifconfig ' + iface, shell=True)[0]
		result = re.search(self._ip_re, out)
		if not result:
			return None		
		return result.group('ip')
	
	def get_access_keys(self):
		return (os.environ['CLOUD_SERVERS_USERNAME'], os.environ['CLOUD_SERVERS_API_KEY'])
	
	def set_access_data(self, access_data):
		Platform.set_access_data(self, access_data)
		os.environ['CLOUD_SERVERS_USERNAME'] = self.get_access_data("username").encode("ascii")
		os.environ['CLOUD_SERVERS_API_KEY'] = self.get_access_data("api_key").encode("ascii")
	
	def clear_access_data(self):
		try:
			del os.environ['CLOUD_SERVERS_USERNAME']
			del os.environ['CLOUD_SERVERS_API_KEY']
		except KeyError:
			pass
	
	def new_cloudservers_conn(self):
		return new_cloudserver_conn()
	
	def new_cloudfiles_conn(self):
		return new_cloudfiles_conn()
	
	@property
	def cloud_storage_path(self):
		return 'cf://%s' % self.get_user_data('cf_container')
		