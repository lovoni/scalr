from scalarizr.libs.pubsub import Observable
from scalarizr.util import xml_strip
import xml.dom.minidom as dom
import threading
import logging

class MessagingError(BaseException):
	pass

class MessageServiceFactory(object):
	_adapters = {}
	
	def new_service (self, name, **params):
		if not self._adapters.has_key(name):
			adapter =  __import__("scalarizr.messaging." + name, 
					globals(), locals(), ["new_service"])
			self._adapters[name] = adapter
		return self._adapters[name].new_service(**params)


class MessageService(object):
	def new_message(self, name=None, meta=None, body=None):
		pass
	
	def new_consumer(self, **params):
		pass
	
	def new_producer(self, **params):
		pass

	
class MetaOptions(object):
	SERVER_ID 	= "server_id"
	PLATFORM 	= "platform" # ec2, vps, rs
	OS 			= "os" # linux, win, sunos
	REQUEST_ID 	= "request_id"
	SZR_VERSION = "szr_version"

	
class Message(object):
	
	id = None
	name = None
	meta = None
	body = None
	
	def __init__(self, name=None, meta=None, body=None):
		self.id = None
		self.name = name
		self.meta = meta or {}
		self.body = body or {}
	
	def __setattr__(self, name, value):
		if name in ("id", "name", "meta", "body"):
			object.__setattr__(self, name, value)
		else:
			self.body[name] = value
		
	def __getattr__(self, name):
		return self.body[name] if name in self.body else None
	
	def is_handled(self):
		pass
	
	def is_delivered(self):
		pass
	
	def is_responce_received(self):
		pass
		
	def get_response(self):
		pass
	
	def fromxml (self, xml):
		doc = dom.parseString(xml)
		xml_strip(doc)
		
		root = doc.documentElement
		self.id = root.getAttribute("id")
		self.name = root.getAttribute("name")
		
		for ch in root.firstChild.childNodes:
			self.meta[ch.nodeName] = self._walk_decode(ch)
		for ch in root.childNodes[1].childNodes:
			self.body[ch.nodeName] = self._walk_decode(ch)

	def _walk_decode(self, el):
		if el.firstChild and el.firstChild.nodeType == 1:
			if all((ch.nodeName == "item" for ch in el.childNodes)):
				return list(self._walk_decode(ch) for ch in el.childNodes)
			else:
				return dict(tuple((ch.nodeName, self._walk_decode(ch)) for ch in el.childNodes))
		else:
			return el.firstChild and el.firstChild.nodeValue or None
	
	def __str__(self):
		from xml.dom.minidom import getDOMImplementation
		impl = getDOMImplementation()
		doc = impl.createDocument(None, "message", None)
		
		root = doc.documentElement;
		root.setAttribute("id", str(self.id))
		root.setAttribute("name", str(self.name))
		
		meta = doc.createElement("meta")
		root.appendChild(meta)
		self._walk_encode(self.meta, meta, doc)
		
		body = doc.createElement("body")
		root.appendChild(body)
		self._walk_encode(self.body, body, doc)
			
		return doc.toxml()
	
	toxml = __str__
		
	def _walk_encode(self, value, el, doc):
		if getattr(value, '__iter__', False):
			if getattr(value, "keys", False):
				for k, v in value.items():
					itemEl = doc.createElement(str(k))
					el.appendChild(itemEl)
					self._walk_encode(v, itemEl, doc)
			else:
				for v in value:
					itemEl = doc.createElement("item")
					el.appendChild(itemEl)
					self._walk_encode(v, itemEl, doc)
		else:	
			el.appendChild(doc.createTextNode(str(value) if value is not None else ""))

class MessageProducer(Observable):
	filters = None
	"""
	Out message filter
	Filter is a callable f(producer, queue, message, headers)
	"""
	
	def __init__(self):
		Observable.__init__(self)
		self.filters = {
			'data' : [],
			'protocol' : []
		}
		self.define_events(
			# Fires before message is send
			"before_send", 
			# Fires after message is send
			"send",
			# Fires when error occures
			"send_error"
		)
	
	def send(self, queue, message):
		pass
	
	def shutdown(self):
		pass
	
class MessageConsumer(object):
	filters = None
	"""
	In message filters
	Filter is a callable f(consumer, queue, message)
	"""
	
	listeners = None
	running = False
	
	def __init__(self):
		self.listeners = []
		self.filters = {
			'data' : [],
			'protocol' : []
		}
	
	def start(self):
		pass
	
	def shutdown(self):
		pass
	
class Queues:
	CONTROL = "control"
	LOG = "log"
	
class Messages:
	###
	# Scalarizr events
	###
	
	HELLO = "Hello"
	"""
	Fires when Scalarizr wants to remind Scalr of himself
	@ivar behaviour
	@ivar local_ip
	@ivar remote_ip
	@ivar role_name 
	"""
	
	HOST_INIT = "HostInit"
	"""
	@broadcast
	Fires when scalarizr is initialized and ready to be configured
	@ivar behaviour
	@ivar local_ip
	@ivar remote_ip
	@ivar role_name 
	"""

	HOST_UP = "HostUp"
	"""
	@broadcast
	Fires when server is ready to play it's role
	"""
	
	HOST_DOWN = "HostDown"
	"""
	@broadcast
	Fires when server is terminating
	"""

	REBOOT_START = "RebootStart"
	"""
	Fires when scalarizr is going to reboot
	"""
	
	REBOOT_FINISH = "RebootFinish"
	"""
	@broadcast
	Fires when scalarizr is resumed after reboot
	"""
	
	RESTART = "Restart"
	"""
	@broadcast
	Fires when server is resumed after stop
	"""
	
	BLOCK_DEVICE_ATTACHED = "BlockDeviceAttached"
	"""
	Fires when block device was attached
	"""
	
	BLOCK_DEVICE_DETACHED = "BlockDeviceDetached"
	"""
	Fires when block device was detached
	"""

	BLOCK_DEVICE_MOUNTED = "BlockDeviceMounted"
	"""
	Fires when block device was mounted
	"""
	
	EXEC_SCRIPT_RESULT = "ExecScriptResult"
	"""
	Fires after script execution
	"""
	
	REBUNDLE_RESULT = "RebundleResult"
	"""
	Fires after rebundle task finished
	"""
	
	REBUNDLE_LOG = "RebundleLog"
	
	LOG = "Log"
	
	###
	# Scalr events
	###
	
	VHOST_RECONFIGURE = "VhostReconfigure"

	MOUNTPOINTS_RECONFIGURE = "MountPointsReconfigure"

	HOST_INIT_RESPONSE = "HostInitResponse"
	
	REBUNDLE = "Rebundle"
	
	SCALARIZR_UPDATE_AVAILABLE = "ScalarizrUpdateAvailable"
	
	BEFORE_HOST_TERMINATE = "BeforeHostTerminate"
	
	BEFORE_INSTANCE_LAUNCH = "BeforeInstanceLaunch"
	
	DNS_ZONE_UPDATED = "DNSZoneUpdated"
	
	IP_ADDRESS_CHANGED = "IPAddressChanged"
	
	SCRIPTS_LIST_UPDATED = "ScriptsListUpdated"
	
	EXEC_SCRIPT = "ExecScript"
	
	UPDATE_SERVICE_CONFIGURATION = "UpdateServiceConfiguration"
	
	UPDATE_SERVICE_CONFIGURATION_RESULT = "UpdateServiceConfigurationResult"

	###
	# Internal events
	###
	
	INT_BLOCK_DEVICE_UPDATED = "IntBlockDeviceUpdated"
	"""
	Fired by scripts/udev.py when block device was added/updated/removed 
	"""
	
	INT_SERVER_REBOOT = "IntServerReboot"
	"""
	Fired by scripts/reboot.py when server is going to reboot
	"""
	
	INT_SERVER_HALT = "IntServerHalt"
	"""
	Fired by scripts/halt.py when server is going to halt
	"""
	
	UPDATE_SSH_AUTHORIZED_KEYS = "UpdateSshAuthorizedKeys"
