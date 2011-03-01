
from scalarizr.bus import bus
from scalarizr.platform import Ec2LikePlatform, PlatformError
from scalarizr.storage.transfer import Transfer
from .storage import S3TransferProvider

from boto import connect_ec2, connect_s3
from boto.ec2.regioninfo import RegionInfo
import urllib2, re, os


Transfer.explore_provider(S3TransferProvider)


"""
Platform configuration options
"""
OPT_ACCOUNT_ID = "account_id"
OPT_KEY = "key"
OPT_KEY_ID = "key_id"
OPT_EC2_CERT_PATH = "ec2_cert_path"
OPT_CERT_PATH = "cert_path"
OPT_PK_PATH = "pk_path"


"""
User data options 
"""
UD_OPT_S3_BUCKET_NAME = "s3bucket"


def get_platform():
	return Ec2Platform()

class Ec2Platform(Ec2LikePlatform):
	name = "ec2"

	_userdata_key = "latest/user-data"

	ec2_endpoints = {
		"us-east-1" 		: "ec2.amazonaws.com",
		"us-west-1" 		: "ec2.us-west-1.amazonaws.com",
		"eu-west-1" 		: "ec2.eu-west-1.amazonaws.com",
		"ap-southeast-1" 	: "ec2.ap-southeast-1.amazonaws.com"
	}
	s3_endpoints = {
		'us-east-1' 		: 's3.amazonaws.com',
		'us-west-1' 		: 's3-us-west-1.amazonaws.com',
		'eu-west-1' 		: 's3.amazonaws.com',
		'ap-southeast-1' 	: 's3-ap-southeast-1.amazonaws.com'
	}	

	_logger = None	
	_ec2_cert = None
	_cnf = None
	
	def get_account_id(self):
		return self.get_access_data("account_id").encode("ascii")
			
	def get_access_keys(self):
		# Keys must be in ASCII because hmac functions doesn't works with unicode		
		return (self.get_access_data("key_id").encode("ascii"), self.get_access_data("key").encode("ascii"))
			
	def get_cert_pk(self):
		return (self.get_access_data("cert").encode("ascii"), self.get_access_data("pk").encode("ascii"))
	
	def get_ec2_cert(self):
		if not self._ec2_cert:
			# XXX: not ok
			self._ec2_cert = self._cnf.read_key(os.path.join(bus.etc_path, self._cnf.rawini.get(self.name, OPT_EC2_CERT_PATH)), title="EC2 certificate")
		return self._ec2_cert
	
	def new_ec2_conn(self):
		""" @rtype: boto.ec2.connection.EC2Connection """
		region = self.get_region()
		self._logger.debug("Return ec2 connection (endpoint: %s)", self.ec2_endpoints[region])
		return connect_ec2(region=RegionInfo(name=region, endpoint=self.ec2_endpoints[region]))

	def new_s3_conn(self):
		self._logger.debug("Return s3 connection (endpoint: %s)", self.s3_endpoints[self.get_region()])
		return connect_s3(host=self.s3_endpoints[self.get_region()])
	
	def set_access_data(self, access_data):
		Ec2LikePlatform.set_access_data(self, access_data)
		key_id, key = self.get_access_keys()
		os.environ['AWS_ACCESS_KEY_ID'] = key_id
		os.environ['AWS_SECRET_ACCESS_KEY'] = key

	def clear_access_data(self):
		Ec2LikePlatform.clear_access_data(self)
		try:
			del os.environ['AWS_ACCESS_KEY_ID']
			del os.environ['AWS_SECRET_ACCESS_KEY']
		except KeyError:
			pass
		
	@property
	def cloud_storage_path(self):
		return self.get_user_data('cloud_storage_path') or 's3://' + self.get_user_data(UD_OPT_S3_BUCKET_NAME)

