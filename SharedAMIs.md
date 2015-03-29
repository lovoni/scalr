## Shared AMIs ##

### Region: us-east-1 ###
| **32bit role name** | **64bit role name** | **32bit AMI ID** | **64bit AMI ID** | **Purpose** |
|:--------------------|:--------------------|:-----------------|:-----------------|:------------|
| ~~mysql~~ | ~~mysql64~~ | ~~ami-2cf21645~~ | ~~ami-e8c62281~~ | ~~MySQL server~~ |
| app | app64 | ami-bac420d3 | ami-0ac62263 | Application server (Apache2 + mod\_php + PHP 5.2.4) |
| www | www64 | ami-72f2161b | ami-01ca2e68 | Frontend load balancer and HTTP server |
| base | base64 | ami-51f21638 | ami-03ca2e6a | The clean image for building custom roles |
| mysqllvm | mysqllvm64 | ami-d09572b9 | ami-21cf2b48 | MySQL server (LVM) |
| app-rails | app-rails64 | ami-c2d034ab | ami-69d23600 | Application server (Apache2 + mod\_rails + Rails 2.1.1) |
| memcached |  | ami-cfd034a6 |  | Memcached server |
| app-tomcat |  | ami-5a2fcb33 |  | Application server (Apache Tomcat 5.5) |
|  | app-tomcat6 |  | ami-6436d20d | Application server (Apache Tomcat 6.0) |

<br>
<h3>Region: eu-west-1</h3>

<table><thead><th> <b>32bit role name</b> </th><th> <b>64bit role name</b> </th><th> <b>32bit AMI ID</b> </th><th> <b>64bit AMI ID</b> </th><th> <b>Purpose</b> </th></thead><tbody>
<tr><td> <del>mysql</del> </td><td> <del>mysql64</del> </td><td> <del>ami-221c3456</del> </td><td> <del>ami-201c3454</del> </td><td> <del>MySQL server</del> </td></tr>
<tr><td> app </td><td> app64 </td><td> ami-3c1c3448 </td><td> ami-481c343c </td><td> Application server (Apache2 + mod_php + PHP 5.2.4) </td></tr>
<tr><td> www </td><td> www64 </td><td> ami-441c3430 </td><td> ami-5a1c342e </td><td> Frontend load balancer and HTTP server </td></tr>
<tr><td> base </td><td> base64 </td><td> ami-4c1c3438 </td><td> ami-401c3434 </td><td> The clean image for building custom roles </td></tr>
<tr><td> mysqllvm </td><td> mysqllvm64 </td><td> ami-161c3462 </td><td> ami-241c3450 </td><td> MySQL server (LVM) </td></tr>
<tr><td> app-rails </td><td> app-rails64 </td><td> ami-321c3446 </td><td> ami-301c3444 </td><td> Application server (Apache2 + mod_rails + Rails 2.1.1) </td></tr>
<tr><td> memcached </td><td>  </td><td> ami-421c3436 </td><td>  </td><td> Memcached server </td></tr>
<tr><td> app-tomcat </td><td>  </td><td> ami-341c3440 </td><td>  </td><td> Application server (Apache Tomcat 5.5) </td></tr>
<tr><td>  </td><td> app-tomcat6 </td><td>  </td><td> ami-4a1c343e </td><td> Application server (Apache Tomcat 6.0) </td></tr></tbody></table>


<br>
<i><del>Crossed</del> roles are deprecated</i>