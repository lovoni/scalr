'''
Created on Nov 11, 2010

@author: spike
@author: marat
'''
from . import MOUNT_EXEC
from . import FileSystem, device_should_exists, system 

from scalarizr.util import disttool

import re
import os


JFS_TUNE_EXEC	= '/sbin/jfs_tune'

class JfsFileSystem(FileSystem):
	name = 'jfs'
	umount_on_resize = False
		
	_label_re  = None
	
	def __init__(self):
		self._label_re  = re.compile("volume\s+label:\s+'(?P<label>.*)'", re.IGNORECASE)
		if not os.path.exists(JFS_TUNE_EXEC):
			system(('/sbin/modprobe', 'jfs'), error_text="Cannot load 'jfs' kernel module")
			if disttool.is_redhat_based():
				system(('/usr/bin/yum', '-y', 'install', 'jfsutils'))
			else:
				system(('/usr/bin/apt-get', '-y', 'install', 'jfsutils'))			
		
	def mkfs(self, device, options=None):
		FileSystem.mkfs(self, device, ('-q',))
		
	@device_should_exists		
	def set_label(self, device, label):
		cmd = (JFS_TUNE_EXEC, '-L', label, device)
		system(cmd, error_text=self.E_SET_LABEL % device)
	
	@device_should_exists
	def get_label(self, device):
		cmd = (JFS_TUNE_EXEC, '-l', device)
		error_text = 'Error while listing contents of the JFS file system on device %s' % device
		out = system(cmd, error_text=error_text)[0]
		
		res = re.search(self._label_re, out)
		return res.group('label') if res else ''
	
	@device_should_exists
	def resize(self, device, size=None, **options):
		mpoint = self._check_mounted(device)
		cmd = (MOUNT_EXEC, '-o', 'remount,resize', mpoint)
		system(cmd, error_text=self.E_RESIZE % device)		

__filesystem__ = JfsFileSystem
