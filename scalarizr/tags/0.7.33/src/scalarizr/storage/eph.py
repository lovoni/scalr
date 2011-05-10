'''
Created on Jan 6, 2011

@author: marat
'''
from __future__ import with_statement
from . import VolumeConfig, Volume, Snapshot, VolumeProvider, Storage, StorageError
from .transfer import Transfer
from .util.lvm2 import Lvm2, lvm_group_b64, Lvm2Error
from .util import ramdisk

from scalarizr.libs.metaconf import Configuration
from scalarizr.util.software import whereis
from scalarizr.util.fstool import mount, umount, mkfs
from scalarizr.util import firstmatched

from Queue import Queue, Empty
from tempfile import mkdtemp

import subprocess
import threading
import cStringIO
import binascii
import hashlib
import logging
import time
import os

LVM_EXTENT_SIZE = 4*1024*1024

class EphConfig(VolumeConfig):
	type = 'eph'
	vg = None
	lvm_group_cfg = None
	disk = None
	size = None
	path = None
	snap_backend = None
	snap_strategy = None

class EphVolume(Volume, EphConfig):
	_ignores = ('path', 'snap_strategy')	

class EphSnapshot(Snapshot, EphConfig):
	pass

TRANZIT_VOL_MPOINT	= '/mnt/tranzit'
TRANZIT_VOL_SIZE	= 205

class EphVolumeProvider(VolumeProvider):
	type = 'eph'
	vol_class = EphVolume
	snap_class = EphSnapshot
	
	_lvm = None
	_snap_pvd = None
	
	def __init__(self):
		self._lvm = Lvm2()
		self._snap_pvd = EphSnapshotProviderLite()
	
	def _create_layout(self, pv, vg, size):
		''' 
		Creates LV layout
		      [Disk]
		        |
		       [VG]
		      /   \ 
		  [Data] [Tranzit]
		'''

		# Create PV
		self._lvm.create_pv(pv)		

		# Create VG
		if not isinstance(vg, dict):
			vg = dict(name=vg)
		vg_name = vg['name']
		del vg['name']
		vg = self._lvm.create_vg(vg_name, [pv], **vg)
		vg = os.path.basename(vg)
		
		# Create data volume
		lv_kwargs = dict()
		
		size = size or '80%'
		size = str(size)
		if size[-1] == '%':
			lv_kwargs['extents'] = '%sVG' % size
		else:
			lv_kwargs['size'] = int(size)

		data_lv = self._lvm.create_lv(vg, 'data', **lv_kwargs)

		# Create tranzit volume (should be 5% bigger then data vol)
		#lvi = self._lvm.lv_info(data_lv)
		#size_in_KB = int(read_file('/sys/block/dm-%s/size' % lvi.lv_kernel_minor)) / 2
		#tranzit_lv = self._lvm.create_lv(vg, 'tranzit', size='%dK' % (size_in_KB*1.05,))

		return (vg, data_lv, size)

	def _destroy_layout(self, vg, data_lv):
		# Find PV 
		pv = None
		pvi = firstmatched(lambda pvi: vg in pvi.vg, self._lvm.pv_status())
		if pvi:
			pv = pvi.pv
			
		# Remove storage VG
		self._lvm.change_lv(data_lv, available=False)
		self._lvm.remove_vg(vg)
		
		if pv:
			# Remove PV if it doesn't belongs to any other VG
			pvi = self._lvm.pv_info(pv)
			if not pvi.vg:
				self._lvm.remove_pv(pv)		
	
	def create(self, **kwargs):
		'''
		@param disk: Physical volume
		@param vg: Uniting volume group
		@param size: Useful storage size (in % of physican volume or MB)
		@param snap_backend: Snapshot backend
		
		Example: 
		Storage.create({
			'type': 'eph',
			'disk': '/dev/sdb',
			'size': '40%',
			'vg': {
				'name': 'mysql_data',
				'ph_extent_size': 10
			},
			'snap_backend': 'cf://mysql_backups/cloudsound/production',
			'snap_strategy' : 'data'|'device'
		})
		'''
		initialized = False
		if 'device' in kwargs:
			try:
				self._lvm.pv_scan()
				self._lvm.change_vg(kwargs['vg'], available=True)
				lvi = self._lvm.lv_info(kwargs['device'])
				gvi = self._lvm.vg_info(kwargs['vg'])
				initialized = lvi.path == kwargs['device'] and gvi.vg == kwargs['vg']
			except (LookupError, Lvm2Error):
				pass
		
		if not initialized:
			if kwargs.get('lvm_group_cfg'):
				self._lvm.restore_vg(kwargs['vg'], cStringIO.StringIO(kwargs['lvm_group_cfg']))
			else:
				# Create LV layout
				kwargs['vg'], kwargs['device'], kwargs['size'] = self._create_layout(
						kwargs['disk'].devname, vg=kwargs.get('vg'), size=kwargs.get('size'))
			
		# Accept snapshot backend
		if not isinstance(kwargs['snap_backend'], dict):
			kwargs['snap_backend'] = dict(path=kwargs['snap_backend'])
		
		return super(EphVolumeProvider, self).create(**kwargs)

	def create_from_snapshot(self, **kwargs):
		'''
		...
		@param path: Path to snapshot manifest on remote storage
		
		Example: 
		Storage.create(**{
			'disk' : {
				'type' : 'loop',
				'file' : '/media/storage',
				'size' : 1000
			}
			'snapshot': {
				'type': 'eph',
				'description': 'Last winter mysql backup',
				'path': 'cf://mysql_backups/cloudsound/production/snap-14a356de.manifest.ini'
				'size': '40%',
				'vg': 'mysql_data'
			}
		})
		'''
		_kwargs = kwargs.copy()
		if 'id' in _kwargs:
			del _kwargs['id']
		
		if not 'snap_backend' in _kwargs:
			_kwargs['snap_backend'] = os.path.dirname(_kwargs['path'])
		vol = self.create(**_kwargs)

		snap = self.snapshot_factory(**kwargs)
		free_ram, free_swap = ramdisk.free()
		if (free_ram + free_swap) < TRANZIT_VOL_SIZE:
			raise Exception('Instance has no enough free ram to create tranzit ramdisk')
		
		ramdisk.create(TRANZIT_VOL_SIZE, TRANZIT_VOL_MPOINT)
		
		try:
			self._snap_pvd.download_and_restore(vol, snap, TRANZIT_VOL_MPOINT)
		finally:
			ramdisk.destroy(TRANZIT_VOL_MPOINT, force=True)

		"""
		try:
			self._snap_pvd.prepare_tranzit_vol(vol.tranzit_vol)
			self._snap_pvd.download(vol, snap, vol.tranzit_vol.mpoint)
			self._snap_pvd.restore(vol, snap, vol.tranzit_vol.mpoint)			
		finally:
			self._snap_pvd.cleanup_tranzit_vol(vol.tranzit_vol)
		"""
		return vol

	def create_snapshot(self, vol, snap):
		ramdisk.create(TRANZIT_VOL_SIZE, TRANZIT_VOL_MPOINT)
		cleanup = lambda: ramdisk.destroy(TRANZIT_VOL_MPOINT, force=True)
		return self._snap_pvd.create(vol, snap, TRANZIT_VOL_MPOINT, cleanup)


	def get_snapshot_state(self, snap):
		return self._snap_pvd.get_snapshot_state(snap)


	def detach(self, vol, force=False):
		'''
		@type vol: EphVolume
		'''
		super(EphVolumeProvider, self).detach(vol, force)
		if vol.vg:
			vol.lvm_group_cfg = lvm_group_b64(vol.vg)
			self._destroy_layout(vol.vg, vol.devname)
		vol.disk.detach(force)
		return vol.config()

	def destroy(self, vol, force=False, **kwargs):
		super(EphVolumeProvider, self).destroy(vol, force, **kwargs)
		self._destroy_layout(vol.vg, vol.device)
		vol.disk.destroy(force=force)

Storage.explore_provider(EphVolumeProvider)


class EphSnapshotProviderLite(object):
	
	MANIFEST_NAME 		= 'manifest.ini'
	SNAPSHOT_LV_NAME 	= 'snap'	
	
	chunk_size = None
	'''	Data chunk size in Mb '''

	_logger		= None	
	_transfer	= None
	_lvm		= None
	_state_map	= None
	_upload_queue= None
	_chunks_md5 = None
	_read_finished = None
	
	def __init__(self, chunk_size=100):
		self.chunk_size			= chunk_size		
		self._logger 			= logging.getLogger(__name__)
		self._lvm 				= Lvm2()
		self._state_map 		= dict()
		self._upload_queue 		= Queue(2)
		self._download_queue	= Queue()
		self._writer_queue		= Queue(2)
		self._chunks_md5		= {}
		self._read_finished 	= threading.Event()
		self._download_finished = threading.Event()
		self._slot_available	= threading.Semaphore(2)
		self._transfer_cls  	= Transfer
	
	def create(self, volume, snapshot, tranzit_path, complete_cb=None):
		try:
			if snapshot.id in self._state_map:
				raise StorageError('Snapshot %s is already %s. Cannot create it again' % (
						snapshot.id, self._state_map[snapshot.id]))
			self._state_map[snapshot.id] = Snapshot.CREATING
			#self.prepare_tranzit_vol(volume.tranzit_vol)
			snap_lv = self._lvm.create_lv_snapshot(volume.devname, self.SNAPSHOT_LV_NAME, extents='100%FREE')		
			self._logger.info('Created LVM snapshot %s for volume %s', snap_lv, volume.device)
			t = threading.Thread(name='%s creator' % snapshot.id, target=self._create, 
								args=(volume, snapshot, snap_lv, tranzit_path, complete_cb))
			t.start()
		except:
			if complete_cb:
				complete_cb()
		snapshot.snap_strategy = 'data'
		return snapshot

	def _create(self, volume, snapshot, snap_lv, tranzit_path,  complete_cb):
		try:
			chunk_prefix = '%s.data' % snapshot.id
			snapshot.path = None
			snap_mpoint = mkdtemp()
			mount(snap_lv, snap_mpoint)
			try:
				#cmd1 = ['dd', 'if=%s' % snap_lv]
				tar_cmd = ['tar', 'cp', '-C', snap_mpoint, '.']
				
				pigz_bins = whereis('pigz')
				compress_cmd = [pigz_bins[0] if pigz_bins else 'gzip', '-5'] 
				
				self._logger.debug("Creating and compressing snapshot data.")
				tar = subprocess.Popen(tar_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, close_fds=True)
				compressor = subprocess.Popen(compress_cmd, stdin=tar.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE, close_fds=True)
				reader = threading.Thread(target=self._reader, name='reader', 
						  args=(compressor.stdout, tranzit_path, chunk_prefix, snapshot))
				reader.start()
				
				uploaders = []
				for i in range(2):
					uploader = threading.Thread(name="Uploader-%s" % i, target=self._uploader, 
											  args=(volume.snap_backend['path'], snapshot))
					self._logger.debug("Starting uploader '%s'", uploader.getName())
					
					uploader.start()
					uploaders.append(uploader)
				reader.join()
				for uploader in uploaders:
					uploader.join()
				
				r_code = tar.wait()
				if r_code:
					raise StorageError('Tar process finished with code %s' % r_code)

				r_code = compressor.wait()
				if r_code:
					raise StorageError('Pigz process finished with code %s' % r_code)				

			finally:
				umount(snap_mpoint, options=('-f',))
				os.rmdir(snap_mpoint)
				self._lvm.remove_lv(snap_lv)
			self._state_map[snapshot.id] = Snapshot.COMPLETED
		except (Exception, BaseException), e:
			self._state_map[snapshot.id] = Snapshot.FAILED
			self._logger.exception('Snapshot creation failed. %s' % e)
		finally:
			if complete_cb:
				complete_cb()
	
	def _reader(self, stdin, tranzit_path, chunk_prefix, snapshot):
		try:
			self._read_finished.clear()
			chunk_max_size = 100*1024*1024
			piece_rest = ''
			index = 0
			chunk_size = 0
			chunk_md5 = hashlib.md5()
			chunk_path = os.path.join(tranzit_path, chunk_prefix + '.tar.gz.%03d'%index)
			chunk_fp = open(chunk_path, 'wb' )
	
			while True:
				piece = stdin.read(LVM_EXTENT_SIZE)
				if not piece and not piece_rest:

					if not chunk_fp.closed:
						chunk_fp.close()
					if chunk_size:
						self._upload_queue.put(chunk_path)
						self._chunks_md5[os.path.basename(chunk_path)] = binascii.hexlify(chunk_md5.digest())

					manifest_path = self._write_manifest(snapshot, tranzit_path)
					self._upload_queue.put(manifest_path)

					break
				
				if piece_rest:
					piece = piece_rest + piece
					piece_rest = ''				
	
				if (chunk_size + len(piece)) > chunk_max_size:
					rest_len = chunk_size + len(piece) - chunk_max_size
					piece_rest = piece[-rest_len:]
					piece = piece[:-rest_len]
	
				
				if chunk_fp.closed:
					with self._slot_available:
						chunk_path = os.path.join(tranzit_path, chunk_prefix + '.tar.gz.%03d'%index)
						chunk_fp = open(chunk_path, 'wb' )
				

				chunk_fp.write(piece)
				chunk_size += len(piece)
				chunk_md5.update(piece)

					
				if chunk_size == chunk_max_size:
					chunk_fp.close()
					self._upload_queue.put(chunk_path)
					self._chunks_md5[os.path.basename(chunk_path)] = binascii.hexlify(chunk_md5.digest())
					chunk_md5 = hashlib.md5()
					index += 1
					chunk_size = 0
		finally:
			self._read_finished.set()
			stdin.close()

		
	def _uploader(self, dst, snapshot):
		"""
		@rtype: tuple
		"""
		transfer = self._transfer_cls()
		while True:
			try:
				chunk_path = self._upload_queue.get(False)
			except Empty:
				if self._read_finished.is_set():
					break
				continue
			
			with self._slot_available:
				link = transfer.upload([chunk_path], dst)[0]
				os.remove(chunk_path)
			
			if 'manifest.ini' in link:
				snapshot.path = link

				
	def _downloader(self, tranzit_path):
		transfer = self._transfer_cls()
		while True:
			if self._download_queue.empty():
				self._download_finished.set()
				break
			
			if not self._writer_queue.empty():
				continue

			link, md5 = self._download_queue.get()
				
			transfer.download((link,), tranzit_path)
			chunk_path = os.path.join(tranzit_path, os.path.basename(link))
			if self._md5sum(chunk_path) != md5:
				raise Exception('Md5sum is not correct')
			self._writer_queue.put(chunk_path) 
			
	def _write_manifest(self, snapshot, tranzit_path):
		''' Make snapshot manifest '''
		manifest_path = os.path.join(tranzit_path, '%s.%s' % (snapshot.id, self.MANIFEST_NAME))		
		self._logger.info('Writing snapshot manifest file in %s', manifest_path)
		config = Configuration('ini')
		config.add('snapshot/description', snapshot.description, force=True)
		config.add('snapshot/created_at', time.strftime("%Y-%m-%d %H:%M:%S"))
		config.add('snapshot/pack_method', 'pigz') # Not used yet
		for chunk, md5 in self._chunks_md5.iteritems():
			config.add('chunks/%s' % chunk, md5, force=True)
		
		config.write(manifest_path)
		
		return manifest_path


	def get_snapshot_state(self, snapshot):
		return self._state_map[snapshot.id]

	def download_and_restore(self, volume, snapshot, tranzit_path):
		# Load manifest
		self._download_finished.clear()
		transfer = self._transfer_cls()
		mnf_path = transfer.download(snapshot.path, tranzit_path)
		mnf = Configuration('ini')
		mnf.read(mnf_path)
		
		if snapshot.fstype:
			mkfs(volume.devname, snapshot.fstype)	

		remote_path = os.path.dirname(snapshot.path)
		# Get links with md5 sums
		links = [(os.path.join(remote_path, chunk[0]), chunk[1]) for chunk in mnf.items('chunks')]
		links.sort()

		# Download 2 first chunks
		for link in links[:2]:
			transfer.download(link[0], tranzit_path)
			chunk_path = os.path.join(tranzit_path, os.path.basename(link[0]))
			if self._md5sum(chunk_path) != link[1]:
				raise Exception("md5sum of chunk %s is not correct." % chunk_path)
			self._writer_queue.put(chunk_path)

		if hasattr(snapshot, 'snap_strategy') and snapshot.snap_strategy == 'data':
			restore_strategy = DataRestoreStrategy(self._logger)
		else:
			restore_strategy = DeviceRestoreStrategy(self._logger)
		
		writer = threading.Thread(target=restore_strategy.restore, name='writer', 
								args=(self._writer_queue, volume, self._download_finished))
		writer.start()

		# Add remaining files to download queue
		for link in links[2:]:
			self._download_queue.put(link)
			
		downloader = threading.Thread(name="Downloader", target=self._downloader, 
									  args=(tranzit_path,))
		downloader.start()
		downloader.join()
		writer.join()			
	
	def _md5sum(self, file, block_size=4096):
		fp = open(file, 'rb')
		try:
			md5 = hashlib.md5()
			while True:
				data = fp.read(block_size)
				if not data:
					break
				md5.update(data)
			return binascii.hexlify(md5.digest())
		finally:
			fp.close()
			

class RestoreStrategy:
	def __init__(self, logger):
		#self._logger = logging.getLogger(__name__)
		self._logger = logger
		
	def concat_chunks(self, queue, download_finished, stdout):
		while True:
			try:
				chunk_path = queue.get(False)
			except Empty:
				if download_finished.is_set():
					break
				continue
			chunk_fp = open(chunk_path)
			while True:
				piece = chunk_fp.read(LVM_EXTENT_SIZE)
				if not piece:
					chunk_fp.close()
					os.unlink(chunk_path)
					break
				stdout.write(piece)

class DataRestoreStrategy(RestoreStrategy):
	def restore(self, queue, volume, download_finished):
		tmp_mpoint = mkdtemp()
		volume.mount(tmp_mpoint)
		try:
			pigz_bins = whereis('pigz')
			cmd1 = ('pigz' if pigz_bins else 'gzip', '-d')
			cmd2 = ('tar', 'px', '-C', tmp_mpoint)

			compressor = subprocess.Popen(cmd1, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, close_fds=True)
			tar	 = subprocess.Popen(cmd2, stdin=compressor.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE, close_fds=True)
			self.concat_chunks(queue, download_finished, compressor.stdin)
			
			compressor.stdin.close()
			r_code = compressor.wait()
			if r_code:
				raise Exception('Archiver finished with return code %s' % r_code)

			r_code = tar.wait()
			if r_code:
				raise Exception('Tar finished with return code %s' % r_code)
		finally:
			umount(mpoint=tmp_mpoint, options=('-f', ))

class DeviceRestoreStrategy(RestoreStrategy):
	def restore(self, queue, volume, download_finished):
		device_fp = open(volume.devname, 'w')
		pigz_bins = whereis('pigz')
		cmd = ('pigz' if pigz_bins else 'gzip', '-d')
		compressor = subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=device_fp, stderr=subprocess.PIPE, close_fds=True)
		self.concat_chunks(queue, download_finished, compressor.stdin)
				
		compressor.stdin.close()

		ret_code = compressor.wait()
		if ret_code:
			raise StorageError('Snapshot decompression failed.')		

"""
class EphSnapshotProvider(object):

	MANIFEST_NAME 		= 'manifest.ini'
	SNAPSHOT_LV_NAME 	= 'snap'	
	
	chunk_size = None
	'''	Data chunk size in Mb '''

	_logger = None	
	_transfer = None
	_lvm = None
	_state_map = None
	
	def __init__(self, chunk_size=100):
		self.chunk_size = chunk_size		
		self._logger = logging.getLogger(__name__)
		self._transfer = Transfer()
		self._lvm = Lvm2()
		self._state_map = dict()
	
	def create(self, volume, snapshot, complete_cb=None):
		if snapshot.id in self._state_map:
			raise StorageError('Snapshot %s is already %s. Cannot create it again' % (
					snapshot.id, self._state_map[snapshot.id]))
		self._state_map[snapshot.id] = Snapshot.CREATING
		self.prepare_tranzit_vol(volume.tranzit_vol)
		snap_lv = self._lvm.create_lv_snapshot(volume.devname, self.SNAPSHOT_LV_NAME, extents='100%FREE')
		self._logger.info('Created LVM snapshot %s for volume %s', snap_lv, volume.device)
		t = threading.Thread(name='%s creator' % snapshot.id, target=self._create, 
							args=(volume, snapshot, snap_lv))
		t.start()
		return snapshot

	def prepare_tranzit_vol(self, vol):
		os.makedirs(vol.mpoint)
		vol.mkfs()
		vol.mount()
		
	def cleanup_tranzit_vol(self, vol):
		vol.umount()
		if os.path.exists(vol.mpoint):
			os.rmdir(vol.mpoint)

	def _create(self, volume, snapshot, snap_lv):
		try:
			tranzit_path = volume.tranzit_vol.mpoint
			chunk_prefix = '%s.data' % snapshot.id			
			try:
				self._copy_gzip_split(snap_lv, tranzit_path, chunk_prefix)
			finally:
				self._lvm.remove_lv(snap_lv)
			#snapshot.lvm_group_cfg = lvm_group_b64(snapshot.vg)			
			snapshot.path = self._write_manifest(snapshot, tranzit_path, chunk_prefix)
			snapshot.path = self._upload(volume, snapshot, tranzit_path)
			self._state_map[snapshot.id] = Snapshot.COMPLETED
		except:
			self._state_map[snapshot.id] = Snapshot.FAILED
			self._logger.exception('Snapshot creation failed')
		finally:
			self.cleanup_tranzit_vol(volume.tranzit_vol)

	def _copy_gzip_split(self, device, tranzit_path, chunk_prefix):
		''' Copy | gzip | split snapshot into tranzit volume directory '''
		self._logger.info('Packing %s -> %s', device, tranzit_path)		
		cmd1 = ['dd', 'if=%s' % device]
		cmd2 = ['gzip', '-1']
		cmd3 = ['split', '-a','3', '-d', '-b', '%sm' % self.chunk_size, '-', '%s/%s.gz.' % 
				(tranzit_path, chunk_prefix)]
		p1 = subprocess.Popen(cmd1, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
		p2 = subprocess.Popen(cmd2, stdin=p1.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
		p3 = subprocess.Popen(cmd3, stdin=p2.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
		out, err = p3.communicate()

		if p3.returncode:
			p1.stdout.close()
			p2.stdout.close()				
			p1.wait()
			p2.wait()
			raise StorageError('Error during coping LVM snapshot device (code: %d) <out>: %s <err>: %s' % 
					(p3.returncode, out, err))

	def _write_manifest(self, snapshot, tranzit_path, chunk_prefix):
		''' Make snapshot manifest '''
		manifest_path = os.path.join(tranzit_path, '%s.%s' % (snapshot.id, self.MANIFEST_NAME))		
		self._logger.info('Writing snapshot manifest file in %s', manifest_path)
		config = Configuration('ini')
		config.add('snapshot/description', snapshot.description, force=True)
		config.add('snapshot/created_at', time.strftime("%Y-%m-%d %H:%M:%S"))
		config.add('snapshot/pack_method', 'gzip') # Not used yet
		for chunk in glob.glob(os.path.join(tranzit_path, chunk_prefix + '*')):
			config.add('chunks/%s' % os.path.basename(chunk), self._md5sum(chunk), force=True)
		
		config.write(manifest_path)
		
		return manifest_path
	
	def _upload(self, volume, snapshot, tranzit_path):
		''' Upload manifest and chunks to cloud storage '''
		mnf = Configuration('ini')
		mnf.read(snapshot.path)
		num_chunks = len(mnf.options('chunks'))
		self._logger.info('Uploading %d chunks into cloud storage (total transfer: %dMb)', 
						num_chunks, self.chunk_size*num_chunks)		
		
		files = [snapshot.path]
		files += [os.path.join(tranzit_path, chunk) for chunk in mnf.options('chunks')]
		
		return self._transfer.upload(files, volume.snap_backend['path'])[0]	
	
	def restore(self, volume, snapshot, tranzit_path):
		# Load manifest
		mnf = Configuration('ini')
		mnf.read(os.path.join(tranzit_path, os.path.basename(snapshot.path)))
		
		# Checksum
		for chunk, md5sum_o in mnf.items('chunks'):
			chunkpath = os.path.join(tranzit_path, chunk)
			md5sum_a = self._md5sum(chunkpath)
			if md5sum_a != md5sum_o:
				raise StorageError(
						'Chunk file %s checksum mismatch. Actual md5sum %s != %s defined in snapshot manifest', 
						chunkpath, md5sum_a, md5sum_o)

		# Restore chunks 
		self._logger.info('Unpacking snapshot from %s -> %s', tranzit_path, volume.devname)
		chunks = list(os.path.join(tranzit_path, chunk) for chunk in mnf.options('chunks'))
		chunks.sort()
		#self._gunzip_subprocess(chunks, volume.devname)
		self._gunzip_native(chunks, volume.devname)

	def _gunzip_subprocess(self, chunks, device):
		self._logger.debug('Decompress chunks with `cat | gunzip`')
		cat = ['cat']
		cat.extend(chunks)
		gunzip = ['gunzip']
		dest = open(device, 'w')
		#Todo: find out where to extract file
		try:
			p1 = subprocess.Popen(cat, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
			p2 = subprocess.Popen(gunzip, stdin=p1.stdout, stdout=dest, stderr=subprocess.PIPE)
			out, err = p2.communicate()
			if p2.returncode:
				p1.stdout.close()
				p1.wait()
				raise StorageError('Error during snapshot restoring (code: %d) <out>: %s <err>: %s' % 
						(p2.returncode, out, err))
		finally:
			dest.close()			

	def _gunzip_native(self, chunks, device):
		self._logger.debug('Decompress chunks with zlib')
		dest = open(device, 'w')
		try:
			dec = zlib.decompressobj(16 + zlib.MAX_WBITS)
			for chunk in chunks:
				fp = None
				try:
					fp = open(chunk, 'r')
					while True:
						data = fp.read(8192)
						if not data:
							break
						dest.write(dec.decompress(data))
				finally:
					if fp:
						fp.close()
		finally:
			dest.close()

	def get_snapshot_state(self, snapshot):
		return self._state_map[snapshot.id]

	def download(self, volume, snapshot, tranzit_path):
		# Load manifest
		mnf_path = self._transfer.download(snapshot.path, tranzit_path)[0]
		mnf = Configuration('ini')
		mnf.read(mnf_path)
		
		# Load files
		remote_path = os.path.dirname(snapshot.path)
		files = tuple(os.path.join(remote_path, chunk) for chunk in mnf.options('chunks'))
		self._transfer.download(files, tranzit_path)

	def _md5sum(self, file, block_size=4096):
		fp = open(file, 'rb')
		try:
			md5 = hashlib.md5()
			while True:
				data = fp.read(block_size)
				if not data:
					break
				md5.update(data)
			return binascii.hexlify(md5.digest())
		finally:
			fp.close()
"""			
