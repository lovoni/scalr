'''
Created on Dec 5, 2009

@author: marat
'''

# Core
from scalarizr.messaging import MessageConsumer, MessagingError
from scalarizr.messaging.p2p import P2pMessageStore, P2pMessage
from scalarizr.util import wait_until, system2

# Stdlibs
from BaseHTTPServer import BaseHTTPRequestHandler, HTTPServer
from urlparse import urlparse
import threading
import logging
import sys
import os
import time 
import socket

class P2pMessageConsumer(MessageConsumer):
	endpoint = None
	_logger = None
	_server = None
	_handler_thread = None
	#_not_empty = None
	handler_locked = False
	handler_status = 'stopped'
	
	def __init__(self, endpoint=None):
		MessageConsumer.__init__(self)
		self._logger = logging.getLogger(__name__)		
		self.endpoint = endpoint
		
		self._handler_thread = threading.Thread(name='MessageHandler', target=self.message_handler)
		self.message_to_ack = None
		#self._not_empty = threading.Event()
			
	def start(self):
		if self.running:
			raise MessagingError('Message consumer is already running')

		try:
			if self._server is None:
				r = urlparse(self.endpoint)
				self._logger.info('Building message consumer server on %s:%s', r.hostname, r.port)				
				server_class = HTTPServer if sys.version_info >= (2,6) else _HTTPServer25
				self._server = server_class((r.hostname, r.port), self._get_request_handler_class())
		except (BaseException, Exception), e:
			self._logger.error("Cannot build server. %s", e)
			return
			
		self._logger.debug('Starting message consumer %s', self.endpoint)
		try:
			self.running = True
			self._handler_thread.start() 	# start message handler
			self._server.serve_forever() 	# start http server
		except (BaseException, Exception), e:
			self._logger.exception(e)

	def _get_request_handler_class(self):
		class RequestHandler(BaseHTTPRequestHandler):
			consumer = None
			'''
			@cvar consumer: Message consumer instance
			@type consumer: P2pMessageConsumer 
			'''
	
			def do_POST(self):
				logger = logging.getLogger(__name__)
	
				queue = os.path.basename(self.path)
				rawmsg = self.rfile.read(int(self.headers["Content-length"]))
				logger.debug("Received ingoing message in queue: '%s'", queue)
				
				try:
					for f in self.consumer.filters['protocol']:
						rawmsg = f(self.consumer, queue, rawmsg)
				except (BaseException, Exception), e:
					err = 'Message consumer protocol filter raises exception: %s' % str(e)
					logger.error(err)
					logger.exception(e)
					self.send_response(400, str(e))
					return
				
				try:
					logger.debug("Decoding message")
					message = P2pMessage()
					message.fromxml(rawmsg)
				except (BaseException, Exception), e:
					err = "Cannot decode message. error: %s; xml message: %s" % (str(e), rawmsg)
					logger.error(err)
					logger.exception(e)
					self.send_response(400, err)
					return
				
				
				logger.debug("Received message '%s' (message_id: %s)", message.name, message.id)
				#logger.info("Received ingoing message '%s' in queue %s", message.name, queue)
				
				try:
					store = P2pMessageStore()
					store.put_ingoing(message, queue, self.consumer.endpoint)
					#self.consumer._not_empty.set()					
				except (BaseException, Exception), e: 
					logger.exception(e) 
					self.send_response(500, str(e))
					return
				
				self.send_response(201)
				
				
			def log_message(self, format, *args):
				logger = logging.getLogger(__name__)
				logger.debug("%s %s\n", self.address_string(), format%args)		
		
		RequestHandler.consumer = self
		return RequestHandler
			
	def shutdown(self, force=False):
		self._logger.debug('entring shutdown _server: %s, running: %s', self._server, self.running)
		self.running = False		
		if not self._server:
			return
		
		self._logger.debug('Shutdown message consumer %s ...', self.endpoint)
	
		self._logger.debug("Shutdown HTTP server")
		self._server.shutdown()
		self._server.socket.shutdown(socket.SHUT_RDWR)
		self._server.socket.close()
		#self._server.server_close()
		self._server = None		
		self._logger.debug("HTTP server terminated")

		self._logger.debug("Shutdown message handler")
		self.handler_locked = True
		if not force:
			t = 120
			self._logger.debug('Waiting for message handler to complete it`s task. Timeout: %d seconds', t)
			wait_until(lambda: self.handler_status in ('idle', 'stopped'), 
					timeout=t, error_text='Message consumer is busy', logger=self._logger)
	
		self._handler_thread.join()
		self._logger.debug("Message handler terminated")
		
		self._logger.debug('Message consumer %s terminated', self.endpoint)
		
	def _handle_one_message(self, message, queue, store):
		try:
			self.handler_status = 'running'					
			self._logger.debug('Notify message listeners (message_id: %s)', message.id)
			for ln in self.listeners:
				ln(message, queue)
		except (BaseException, Exception), e:
			self._logger.exception(e)
		finally:
			self._logger.debug('Mark message (message_id: %s) as handled', message.id)
			store.mark_as_handled(message.id)
			self.handler_status = 'idle'			

	def message_handler (self):
		store = P2pMessageStore()
		self.handler_status = 'idle'
		
		self._logger.debug('Starting message handler')
		
		while self.running:
			if not self.handler_locked:
				try:
					if self.message_to_ack:
						for queue, message in store.get_unhandled(self.endpoint):
							#self._logger.debug('Got: %s', message.name)
							if message.name == self.message_to_ack.name and \
									message.meta['server_id'] == self.message_to_ack.meta['server_id']:
								self._handle_one_message(message, queue, store)
								return
						time.sleep(0.1)
						continue
					
					for queue, message in store.get_unhandled(self.endpoint):
						self._handle_one_message(message, queue, store)
												
				except (BaseException, Exception), e:
					self._logger.exception(e)
			time.sleep(0.1)
			
		self.handler_status = 'stopped'
		self._logger.debug('Message handler stopped')		
	

if sys.version_info < (2,6):
	try:
		import selectmodule as select
	except ImportError:
		import select
	class _HTTPServer25(HTTPServer):
		
		def __init__(self, server_address, RequestHandlerClass):
			HTTPServer.__init__(self, server_address, RequestHandlerClass)
			self.__is_shut_down = threading.Event()
			self.__serving = False
		
		def serve_forever(self, poll_interval=0.5):
			logger = logging.getLogger(__name__)
			logger.debug("_HTTPServer25 serving...")
			self.__serving = True
			self.__is_shut_down.clear()
			while self.__serving:
				# XXX: Consider using another file descriptor or
				# connecting to the socket to wake this up instead of
				# polling. Polling reduces our responsiveness to a
				# shutdown request and wastes cpu at all other times.
				r, w, e = select.select([self], [], [], poll_interval)
				if r:
					self.handle_request()
			
			self.__is_shut_down.set()
		
		def shutdown(self):
			self.__serving = False
			self.__is_shut_down.wait()

			