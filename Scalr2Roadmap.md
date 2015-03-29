# A brief roadmap for Scalr 2 development #

Most parts (except web client) will be rewritten in Java.

Unlike the current all-in-one application structure, it will be separated into java API, Web-service (that will just wrap the API) and various clients, including web and console. This will allow you to use remote Scalr API from your own applications, written in any language.

![http://scalr.googlecode.com/files/scalr2_architecture.png](http://scalr.googlecode.com/files/scalr2_architecture.png)


  1. Scalarizr WS daemon. That will run on AMI and reconfigure the OS. A set of bash scripts doing this in v1.
  1. Scalr SDK and simpletest tests for it. This is the heart of the system - a set of classes that do all stuff - create farms, read SQS messages written by AMIs, send commands to AMIs and AWS. Synthetic tests for all possible cases will be created.
  1. Wrap SDK public interface with web service. It will be a simple standalone JAX-WS SOAP (rest can be supported as well, it seems). No heavy application servers/containers, just lightweight native implementation.
  1. Adjust web client that we have now so it will operate with Scalr-WS rather than directly with database/AWS.
  1. Scalarizr part that turns AMI into scalr-compatible image.
  1. Console client.
  1. Development kits for various languages (PHP, C#, ROR).