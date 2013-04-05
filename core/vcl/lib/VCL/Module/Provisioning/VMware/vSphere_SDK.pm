#!/usr/bin/perl -w
###############################################################################
# $Id: vSphere_SDK.pm 1419742 2012-12-10 20:38:49Z arkurth $
###############################################################################
# Licensed to the Apache Software Foundation (ASF) under one or more
# contributor license agreements.  See the NOTICE file distributed with
# this work for additional information regarding copyright ownership.
# The ASF licenses this file to You under the Apache License, Version 2.0
# (the "License"); you may not use this file except in compliance with
# the License.  You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.
###############################################################################

=head1 NAME

VCL::Module::Provisioning::VMware::vSphere_SDK;

=head1 SYNOPSIS

 my $vmhost_datastructure = $self->get_vmhost_datastructure();
 my $vsphere_sdk = VCL::Module::Provisioning::VMware::vSphere_SDK->new({data_structure => $vmhost_datastructure});
 my @registered_vms = $vsphere_sdk->get_registered_vms();

=head1 DESCRIPTION

 This module provides support for the vSphere SDK. The vSphere SDK can be used
 to manage VMware Server 2.x, ESX 3.0.x, ESX/ESXi 3.5, ESX/ESXi 4.0, vCenter
 Server 2.5, and vCenter Server 4.0.

=cut

##############################################################################
package VCL::Module::Provisioning::VMware::vSphere_SDK;

# Specify the lib path using FindBin
use FindBin;
use lib "$FindBin::Bin/../../../..";

# Configure inheritance
use base qw(VCL::Module::Provisioning::VMware::VMware);

# Specify the version of this module
our $VERSION = '2.3.1';

# Specify the version of Perl to use
use 5.008000;

use strict;
use warnings;
use diagnostics;
use English qw( -no_match_vars );
use File::Temp qw( tempdir );
use List::Util qw( max );

use VCL::utils;

##############################################################################

=head1 API OBJECT METHODS

=cut

#/////////////////////////////////////////////////////////////////////////////

=head2 initialize

 Parameters  : none
 Returns     : boolean
 Description : Initializes the vSphere SDK object by establishing a connection
               to the VM host.

=cut

sub initialize {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Newer versions of LWP::Protocol::https have strict SSL checking enabled by default
	# The vSphere SDK won't be able to connect if ESXi or vCenter uses a self-signed certificate
	# The following setting disables strict checking:
	$ENV{PERL_LWP_SSL_VERIFY_HOSTNAME} = 0;
	
	# Override the die handler because process will die if VMware Perl libraries aren't installed
	local $SIG{__DIE__} = sub{};
	
	eval "use VMware::VIRuntime; use VMware::VILib; use VMware::VIExt";
	if ($EVAL_ERROR) {
		notify($ERRORS{'OK'}, 0, "vSphere SDK for Perl does not appear to be installed on this managment node, unable to load VMware vSphere SDK Perl modules, error:\n$EVAL_ERROR");
		return 0;
	}
	notify($ERRORS{'DEBUG'}, 0, "loaded VMware vSphere SDK modules");
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	my $vmhost_username = $self->data->get_vmhost_profile_username();
	my $vmhost_password = $self->data->get_vmhost_profile_password();
	my $vmhost_profile_id = $self->data->get_vmhost_profile_id();
	
	if (!$vmhost_hostname) {
		notify($ERRORS{'WARNING'}, 0, "VM host name could not be retrieved");
		return;
	}
	elsif (!$vmhost_username) {
		notify($ERRORS{'DEBUG'}, 0, "unable to use vSphere SDK, VM host username is not configured in the database for VM profile: $vmhost_profile_id");
		return;
	}
	elsif (!$vmhost_password) {
		notify($ERRORS{'DEBUG'}, 0, "unable to use vSphere SDK, VM host password is not configured in the database for VM profile: $vmhost_profile_id");
		return;
	}
	
	Opts::set_option('username', $vmhost_username);
	Opts::set_option('password', $vmhost_password);
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	# Assemble the URLs to try, URL will vary based on the VMware product
	my @possible_vmhost_urls = (
		"https://$vmhost_hostname/sdk",
		"https://$vmhost_hostname:8333/sdk",
	);
	
	# Also add URLs containing the short host name if the VM hostname is a full DNS name
	if ($vmhost_hostname =~ /\./) {
		my ($vmhost_short_name) = $vmhost_hostname =~ /^([^\.]+)/;
		push @possible_vmhost_urls, "https://$vmhost_short_name/sdk";
		push @possible_vmhost_urls, "https://$vmhost_short_name:8333/sdk";
	}
	
	# Call HostConnect, check how long it takes to connect
	my $vim;
	for my $host_url (@possible_vmhost_urls) {
		Opts::set_option('url', $host_url);
		
		notify($ERRORS{'DEBUG'}, 0, "attempting to connect to VM host: $host_url ($vmhost_username)");
		eval { $vim = Util::connect(); };
		$vim = 'undefined' if !defined($vim);
		my $error_message = $@;
		undef $@;
		
		# It's normal if some connection attempts fail - SSH will be used if the vSphere SDK isn't available
		# Don't display a warning unless the error indicates a configuration problem (wrong username or password)
		# Possible error messages:
		#    Cannot complete login due to an incorrect user name or password.
		#    Error connecting to server at 'https://<VM host>/sdk': Connection refused
		if ($error_message && $error_message =~ /incorrect/) {
			notify($ERRORS{'WARNING'}, 0, "unable to connect to VM host because username or password is incorrectly configured in the VM profile ($vmhost_username/$vmhost_password), error: $error_message");
		}
		elsif (!$vim || $error_message) {
			notify($ERRORS{'DEBUG'}, 0, "unable to connect to VM host using URL: $host_url, error:\n$error_message");
		}
		else {
			notify($ERRORS{'OK'}, 0, "connected to VM host: $host_url, username: '$vmhost_username'");
			last;
		}
	}
	
	if (!$vim) {
		notify($ERRORS{'DEBUG'}, 0, "failed to connect to VM host $vmhost_hostname");
		return;
	}
	elsif (!ref($vim)) {
		notify($ERRORS{'DEBUG'}, 0, "failed to connect to VM host $vmhost_hostname, Util::connect returned '$vim'");
		return;
	}
	else {
		notify($ERRORS{'DEBUG'}, 0, "connected to $vmhost_hostname, VIM object type: " . ref($vim));
		return 1;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_registered_vms

 Parameters  : none
 Returns     : array
 Description : Returns an array containing the vmx file paths of the VMs running
               on the VM host.

=cut

sub get_registered_vms {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	my @vms;
	eval { @vms = @{Vim::find_entity_views(view_type => 'VirtualMachine', begin_entity => $self->_get_datacenter_view())}; };
	
	my @vmx_paths;
	for my $vm (@vms) {
		push @vmx_paths, $self->_get_normal_path($vm->summary->config->vmPathName) || return;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "found " . scalar(@vmx_paths) . " registered VMs:\n" . join("\n", @vmx_paths));
	return @vmx_paths;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 vm_register

 Parameters  : $vmx_file_path
 Returns     : boolean
 Description : Registers the VM specified by vmx file path argument. Returns
               true if the VM is already registered or if the VM was
               successfully registered.

=cut

sub vm_register {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmx path argument and convert it to a datastore path
	my $vmx_path = $self->_get_datastore_path(shift) || return;
	
	my $datacenter = $self->_get_datacenter_view() || return;
	my $vm_folder = Vim::get_view(mo_ref => $datacenter->{vmFolder}) || return;
	
	my $resource_pool = $self->_get_resource_pool_view() || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	my $vm_mo_ref;
	eval { $vm_mo_ref = $vm_folder->RegisterVM(path => $vmx_path,
											  asTemplate => 'false',
											  pool => $resource_pool
											);
	};
	
	if ($@) {
		if ($@->isa('SoapFault') && ref($@->detail) eq 'AlreadyExists') {
			notify($ERRORS{'DEBUG'}, 0, "VM is already registered: $vmx_path");
			return 1;
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to register VM: $vmx_path, error:\n$@");
			return;
		}
	}
	
	if (ref($vm_mo_ref) ne 'ManagedObjectReference' || $vm_mo_ref->type ne 'VirtualMachine') {
		notify($ERRORS{'WARNING'}, 0, "RegisterVM did not return a VirtualMachine ManagedObjectReference:\n" . format_data($vm_mo_ref));
		return;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "registered VM: $vmx_path");
	return 1;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 vm_unregister

 Parameters  : $vmx_file_path or $vm_view or $vm_mo_ref
 Returns     : boolean
 Description : Unregisters the VM specified by vmx file path argument. Returns
               true if the VM is not registered or if the VM was successfully
               unregistered.

=cut

sub vm_unregister {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	my $argument = shift;
	my $vm_view;
	my $vm_name;
	if (my $type = ref($argument)) {
		if ($type eq 'ManagedObjectReference') {
			notify($ERRORS{'DEBUG'}, 0, "argument is a ManagedObjectReference, retrieving VM view");
			$vm_view = Vim::get_view(mo_ref => $argument)
		}
		elsif ($type eq 'VirtualMachine') {
			$vm_view = $argument;
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "invalid argument reference type: '$type', must be either VirtualMachine or ManagedObjectReference");
			return;
		}
		
		$vm_name = $vm_view->{name};
		if (!$vm_name) {
			notify($ERRORS{'WARNING'}, 0, "failed to unregister VM, name could not be determined from VM view:\n" . format_data($vm_view));
			return;
		}
	}
	else {
		$vm_name = $argument;
		$vm_view = $self->_get_vm_view($argument);
	}
	
	my $vmx_path = $vm_view->{summary}{config}{vmPathName};
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	notify($ERRORS{'DEBUG'}, 0, "attempting to unregister VM: '$vm_name' ($vmx_path)");
	
	eval { $vm_view->UnregisterVM(); };
	if ($@) {
		notify($ERRORS{'WARNING'}, 0, "failed to unregister VM: $vm_name, error:\n$@");
		return;
	}
	
	# Delete the cached VM object
	delete $self->{vm_view_objects}{$vmx_path};
	
	notify($ERRORS{'DEBUG'}, 0, "unregistered VM: $vm_name");
	return 1;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 vm_power_on

 Parameters  : $vmx_file_path
 Returns     : boolean
 Description : Powers on the VM specified by vmx file path argument. Returns
               true if the VM was successfully powered on or if it was already
               powered on.

=cut

sub vm_power_on {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmx path argument and convert it to a datastore path
	my $vmx_path = $self->_get_datastore_path(shift) || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	my $vm = $self->_get_vm_view($vmx_path) || return;
	
	eval { $vm->PowerOnVM(); };
	if ($@) {
		if ($@->isa('SoapFault') && ref($@->detail) eq 'InvalidPowerState') {
			my $existing_power_state = $@->detail->existingState->val;
			if ($existing_power_state =~ /on/i) {
				notify($ERRORS{'DEBUG'}, 0, "VM is already powered on: $vmx_path");
				return 1;
			}
		}
		
		notify($ERRORS{'WARNING'}, 0, "failed to power on VM: $vmx_path, error:\n$@");
		return;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "powered on VM: $vmx_path");
	return 1;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 vm_power_off

 Parameters  : $vmx_file_path
 Returns     : boolean
 Description : Powers off the VM specified by vmx file path argument. Returns
               true if the VM was successfully powered off or if it was already
               powered off.

=cut

sub vm_power_off {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmx path argument and convert it to a datastore path
	my $vmx_path = $self->_get_datastore_path(shift) || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	my $vm = $self->_get_vm_view($vmx_path) || return;
	
	eval { $vm->PowerOffVM(); };
	if ($@) {
		if ($@->isa('SoapFault') && ref($@->detail) eq 'InvalidPowerState') {
			my $existing_power_state = $@->detail->existingState->val;
			if ($existing_power_state =~ /off/i) {
				notify($ERRORS{'DEBUG'}, 0, "VM is already powered off: $vmx_path");
				return 1;
			}
		}
		
		notify($ERRORS{'WARNING'}, 0, "failed to power off VM: $vmx_path, error:\n$@");
		return;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "powered off VM: $vmx_path");
	return 1;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_vm_power_state

 Parameters  : $vmx_file_path
 Returns     : string
 Description : Determines the power state of the VM specified by the vmx file
               path argument. A string is returned containing one of the
               following values:
               -on
               -off
               -suspended

=cut

sub get_vm_power_state {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmx path argument and convert it to a datastore path
	my $vmx_path = $self->_get_datastore_path(shift) || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	my $vm = $self->_get_vm_view($vmx_path) || return;
	
	my $power_state = $vm->runtime->powerState->val;
	
	my $return_power_state;
	if ($power_state =~ /on/i) {
		$return_power_state = 'on';
	}
	elsif ($power_state =~ /off/i) {
		$return_power_state = 'off';
	}
	elsif ($power_state =~ /suspended/i) {
		$return_power_state = 'suspended';
	}
	else {
		notify($ERRORS{'WARNING'}, 0, "detected unsupported power state: $power_state");
		$return_power_state = '$power_state';
	}
	
	notify($ERRORS{'DEBUG'}, 0, "power state of VM $vmx_path: $return_power_state");
	return $return_power_state;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _clean_vm_name
 Parameters  : $vm_name
 Returns     : string
 Description : VMWare vCenter supports VM Names of up to 80 characters, but if
               the name is greater than 29 characters, it will truncate the 
               corresponding name and enclosing directory of the virtual disks.

=cut

sub _clean_vm_name {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	my $vm_name = shift || return;
	
	# if the length of the name is > 29, then truncate it in such a way that
	# the image name remains unique in the VCL database
	my $MAX_VMNAME_LEN = 29;
	if (length $vm_name > $MAX_VMNAME_LEN) {
		notify($ERRORS{'DEBUG'}, 0, "truncating VM name $vm_name");
		my $newname = "";
		if ($vm_name =~ m/^(\w+)-(\w+?)(\d*)-(v\d+)$/) {
			my $base = $1;
			my $name = $2;
			my $imgid = $3;
			my $version = $4;
			my $shortened = substr($name, 0, $MAX_VMNAME_LEN - 2 - length($imgid) - length($base) - length($version));
			$newname = $base . "-" . $shortened . $imgid . "-" . $version; 
		}
		else {
			my ($pre_len, $post_len) = ($MAX_VMNAME_LEN - 10, 10);
			my ($pre, $post) = $vm_name =~ m/^(.{$pre_len}).*(.{$post_len})$/;
			$newname = $pre . $post;
		}
		if (get_image_info($newname, 0, 1)) {
			notify($ERRORS{'WARNING'}, 0, "Naming conflict: $newname already exists in the database");
		}
		else {
			notify($ERRORS{'DEBUG'}, 0, "Changed image name to: $newname");
			$vm_name = $newname;
		}
	}
	return $vm_name;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 copy_virtual_disk

 Parameters  : $source_vmdk_file_path, $destination_vmdk_file_path, $disk_type (optional), $adapter_type (optional)
 Returns     : boolean
 Description : Copies a virtual disk (set of vmdk files). This subroutine allows
               a virtual disk to be converted to a different disk type or
               adapter type. The source and destination vmdk file path arguments
               are required.
               
               The disk type argument is optional and may be one of the
               following values:
               -eagerZeroedThick
                  -all space allocated and wiped clean of any previous contents on the physical media at creation time
                  -may take longer time during creation compared to other disk formats
               -flatMonolithic
                  -preallocated monolithic disk
                  -disks in this format can be used with other VMware products
                  -format is only applicable as a destination format in a clone operation
                  -not usable for disk creation
                  -since vSphere API 4.0
               -preallocated
                  -all space allocated at creation time
                  -space is zeroed on demand as the space is used
               -raw
                  -raw device
               -rdm
                  -virtual compatibility mode raw disk mapping
                  -grants access to the entire raw disk and the virtual disk can participate in snapshots
               -rdmp
                  -physical compatibility mode (pass-through) raw disk mapping
                  -passes SCSI commands directly to the hardware
                  -cannot participate in snapshots
               -sparse2Gb, 2Gbsparse
                  -sparse disk with 2GB maximum extent size
                  -can be used with other VMware products
                  -2GB extent size makes these disks easier to burn to dvd or use on filesystems that don't support large files
                  -only applicable as a destination format in a clone operation
                  -not usable for disk creation
               -sparseMonolithic
                  -sparse monolithic disk
                  -can be used with other VMware products
                  -only applicable as a destination format in a clone operation
                  -not usable for disk creation
                  -since vSphere API 4.0
               -thick
                  -all space allocated at creation time
                  -space may contain stale data on the physical media
                  -primarily used for virtual machine clustering
                  -generally insecure and should not be used
                  -due to better performance and security properties, the use of the 'preallocated' format is preferred over this format
               -thick2Gb
                  -thick disk with 2GB maximum extent size
                  -can be used with other VMware products
                  -2GB extent size makes these disks easier to burn to dvd or use on filesystems that don't support large files
                  -only applicable as a destination format in a clone operation
                  -not usable for disk creation
               -thin (default)
                  -space required for thin-provisioned virtual disk is allocated and zeroed on demand as the space is used
                  
               The adapter type argument is optional and may be one of the
               following values:
               -busLogic
               -ide
               -lsiLogic
               
               If the adapter type argument is not specified an attempt will be
               made to retrieve it from the source vmdk file. If this fails,
               lsiLogic will be used.

=cut

sub copy_virtual_disk {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the source and destination path arguments in the datastore path format
	my $source_path = $self->_get_datastore_path(shift) || return;
	my $destination_path = $self->_get_datastore_path(shift) || return;
	
	# Make sure the source path ends with .vmdk
	if ($source_path !~ /\.vmdk$/i || $destination_path !~ /\.vmdk$/i) {
		notify($ERRORS{'WARNING'}, 0, "source and destination path arguments must end with .vmdk:\nsource path argument: $source_path\ndestination path argument: $destination_path");
		return;
	}
	
	
	# Get the adapter type and disk type arguments if they were specified
	# If not specified, set the default values
	my $destination_disk_type = shift || 'thin';
	
	# Fix the disk type in case 2gbsparse was passed
	if ($destination_disk_type =~ /2gbsparse/i) {
		$destination_disk_type = 'sparse2Gb';
	}
	
	# Check the disk type argument, the string must match exactly or the copy will fail
	my @valid_disk_types = qw( eagerZeroedThick flatMonolithic preallocated raw rdm rdmp sparse2Gb sparseMonolithic thick thick2Gb thin );
	if (!grep(/^$destination_disk_type$/, @valid_disk_types)) {
		notify($ERRORS{'WARNING'}, 0, "disk type argument is not valid: '$destination_disk_type', it must exactly match (case sensitive) one of the following strings:\n" . join("\n", @valid_disk_types));
		return;
	}
	
	my $vmhost_name = $self->data->get_vmhost_hostname();
	
	my $source_datastore_name = $self->_get_datastore_name($source_path) || return;
	my $destination_datastore_name = $self->_get_datastore_name($destination_path) || return;
	
	my $source_datastore = $self->_get_datastore_object($source_datastore_name) || return;
	my $destination_datastore = $self->_get_datastore_object($destination_datastore_name) || return;
	
	my $destination_base_name = $self->_get_file_base_name($destination_path);
	
	my $datacenter_view = $self->_get_datacenter_view() || return;
	my $virtual_disk_manager_view = $self->_get_virtual_disk_manager_view() || return;
	
	# Get the source vmdk file info so the source adapter and disk type can be displayed
	my $source_info = $self->_get_file_info($source_path) || return;
	if (scalar(keys %$source_info) != 1) {
		notify($ERRORS{'WARNING'}, 0, "unable to copy virtual disk, multiple source files were found:\n" . format_data($source_info));
	}
	
	my $source_info_file_path = (keys(%$source_info))[0];
	
	my $source_adapter_type = $source_info->{$source_info_file_path}{controllerType} || 'lsiLogic';
	my $source_disk_type = $source_info->{$source_info_file_path}{diskType} || '';
	my $source_file_size_bytes = $source_info->{$source_info_file_path}{fileSize} || '0';
	my $source_file_capacity_kb = $source_info->{$source_info_file_path}{capacityKb} || '0';
	my $source_file_capacity_bytes = ($source_file_capacity_kb * 1024);
	
	# Set the destination adapter type to the source adapter type if it wasn't specified as an argument
	my $destination_adapter_type = shift || $source_adapter_type;
	
	if ($destination_adapter_type =~ /bus/i) {
		$destination_adapter_type = 'busLogic';
	}
	elsif ($destination_adapter_type =~ /lsi/) {
		$destination_adapter_type = 'lsiLogic';
	}
	else {
		$destination_adapter_type = 'ide';
	}
	
	if ($source_adapter_type !~ /\w/ || $source_disk_type !~ /\w/ || $source_file_size_bytes !~ /\d/) {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve adapter type, disk type, and file size of source file on VM host $vmhost_name: '$source_path', file info:\n" . format_data($source_info));
		return;
	}
	
	# Get the destination partent directory path and create the directory
	my $destination_directory_path = $self->_get_parent_directory_datastore_path($destination_path) || return;
	$self->create_directory($destination_directory_path) || return;
	
	notify($ERRORS{'DEBUG'}, 0, "attempting to copy virtual disk on VM host $vmhost_name: '$source_path' --> '$destination_path'\n" .
		"source adapter type: $source_adapter_type\n" .
		"destination adapter type: $destination_adapter_type\n" .
		"disk type: $source_disk_type\n" .
		"source capacity: " . get_file_size_info_string($source_file_capacity_bytes) . "\n" .
		"source space used: " . get_file_size_info_string($source_file_size_bytes)
	);
	
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	# Create a virtual disk spec object
	my $virtual_disk_spec = VirtualDiskSpec->new(
		adapterType => $destination_adapter_type,
		diskType => $destination_disk_type,
	);
	
	my $copy_virtual_disk_result;
	eval {
		$copy_virtual_disk_result = $virtual_disk_manager_view->CopyVirtualDisk(
			sourceName => $source_path,
			sourceDatacenter => $datacenter_view,
			destName => $destination_path,
			destDatacenter => $datacenter_view,
			destSpec => $virtual_disk_spec,
			force => 1
		);
	};
	
	# Check if an error occurred
	if (my $copy_virtual_disk_fault = $@) {
		if ($copy_virtual_disk_fault =~ /No space left/i) {
			# Check if the output indicates there is not enough space to copy the vmdk
			# Output will contain:
			#    Fault string: A general system error occurred: No space left on device
			#    Fault detail: SystemError
			notify($ERRORS{'CRITICAL'}, 0, "failed to copy vmdk on VM host $vmhost_name using CopyVirtualDisk function, no space is left on the destination device: '$destination_path'\nerror:\n$copy_virtual_disk_fault");
			return;
		}
		elsif ($copy_virtual_disk_fault =~ /not implemented/i) {
			notify($ERRORS{'DEBUG'}, 0, "unable to copy vmdk using CopyVirtualDisk function, VM host $vmhost_name does not implement the CopyVirtualDisk function");
			
			# Delete the destination directory path previously created
			$self->delete_file($destination_directory_path);
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to copy vmdk on VM host $vmhost_name using CopyVirtualDisk function: '$source_path' --> '$destination_path'\nerror:\n$copy_virtual_disk_fault");
			return;
		}
	}
	else {
		notify($ERRORS{'OK'}, 0, "copied vmdk on VM host $vmhost_name using CopyVirtualDisk function:\n" . format_data($copy_virtual_disk_result));
		return 1;
	}
	
	
	my $source_vm_name = $self->_clean_vm_name("source_$destination_base_name");
	my $clone_vm_name = $self->_clean_vm_name($destination_base_name);
	
	my $source_vm_directory_path = "[$source_datastore_name] $source_vm_name";
	my $clone_vm_directory_path = "[$destination_datastore_name] $clone_vm_name";
	
	# Make sure the source and clone directories don't exist
	# Otherwise the VM creation/cloning process will create another directory with '_1' appended and the files won't be deleted
	if ($self->file_exists($source_vm_directory_path)) {
		notify($ERRORS{'WARNING'}, 0, "unable to copy virtual disk, source VM directory path already exists: $source_vm_directory_path");
		return;
	}
	if ($self->file_exists($clone_vm_directory_path)) {
		notify($ERRORS{'WARNING'}, 0, "unable to copy virtual disk, clone VM directory path already exists: $clone_vm_directory_path");
		return;
	}
	
	
	my $file_manager = $self->_get_file_manager_view() || return;
	my $resource_pool_view = $self->_get_resource_pool_view() || return;
	
	my $folder_view = Vim::find_entity_view(
		view_type => "Folder",
		begin_entity => $datacenter_view,
		filter => { name => "vm" }
	);
	
	if (!$folder_view){
		notify($ERRORS{'WARNING'}, 0, "failed to retrieve VM folder view");
		return;
	}
	
	# Create a virtual machine on top of this virtual disk
	# First, create a controller for the virtual disk
	my $controller;
	if ($destination_adapter_type eq 'lsiLogic') {
		$controller = VirtualLsiLogicController->new(
			key => 0,
			device => [0],
			busNumber => 0,
			sharedBus => VirtualSCSISharing->new('noSharing')
		);
	}
	else {
		$controller = VirtualBusLogicController->new(
			key => 0,
			device => [0],
			busNumber => 0,
			sharedBus => VirtualSCSISharing->new('noSharing')
		);
	}
	
	# Next create a disk type (it will be the same as the source disk)   
	my $disk_backing_info = ($source_disk_type)->new(
		datastore => $source_datastore,
		fileName => $source_path,
		diskMode => "independent_persistent"
	);
	
	# Create the actual virtual disk
	my $source_vm_disk = VirtualDisk->new(
		key => 0,
		backing => $disk_backing_info,
		capacityInKB => $source_file_capacity_kb,
		controllerKey => 0,
		unitNumber => 0
	);
	
	# Create the specification for creating a source VM
	my $source_vm_config = VirtualMachineConfigSpec->new(
		name => $source_vm_name,
		deviceChange => [
			VirtualDeviceConfigSpec->new(
				operation => VirtualDeviceConfigSpecOperation->new('add'),
				device => $controller
			),
			VirtualDeviceConfigSpec->new(
				operation => VirtualDeviceConfigSpecOperation->new('add'),
				device => $source_vm_disk
			)
		],
		files => VirtualMachineFileInfo->new(
			logDirectory => $source_vm_directory_path,
			snapshotDirectory => $source_vm_directory_path,
			suspendDirectory => $source_vm_directory_path,
			vmPathName => $source_vm_directory_path
		)
	);
	
	# Create the specification for cloning the VM
	my $clone_spec = VirtualMachineCloneSpec->new(
		config => VirtualMachineConfigSpec->new(
			name => $clone_vm_name,
			files => VirtualMachineFileInfo->new(
				logDirectory => $clone_vm_directory_path,
				snapshotDirectory => $clone_vm_directory_path,
				suspendDirectory => $clone_vm_directory_path,
				vmPathName => $clone_vm_directory_path
			)
		),
		powerOn => 0,
		template => 0,
		location => VirtualMachineRelocateSpec->new(
			datastore => $destination_datastore,
			pool => $resource_pool_view,
			diskMoveType => 'moveAllDiskBackingsAndDisallowSharing',
			transform => VirtualMachineRelocateTransformation->new('sparse'),
		)
	);
	
	
	notify($ERRORS{'DEBUG'}, 0, "attempting to copy virtual disk by cloning temporary VM: '$source_path' --> '$destination_path'\n" .
		"adapter type: $source_adapter_type\n" .
		"source disk type: $source_disk_type\n" .
		"source capacity: " . get_file_size_info_string($source_file_capacity_bytes) . "\n" .
		"source space used: " . get_file_size_info_string($source_file_size_bytes) . "\n" .
		"source VM name: $source_vm_name\n" .
		"clone VM name: $clone_vm_name\n" .
		"source VM directory path: $source_vm_directory_path\n" .
		"clone VM directory path: $clone_vm_directory_path"
	);
	
	
	my $source_vm_view;
	my $clone_vm_view;
	eval {
		my $source_vm = $folder_view->CreateVM(
			config => $source_vm_config,
			pool => $resource_pool_view
		);
		if ($source_vm) {
			notify($ERRORS{'DEBUG'}, 0, "created temporary source VM which will be cloned: $source_vm_name");
			$source_vm_view = Vim::get_view(mo_ref => $source_vm);
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to create temporary source VM which will be cloned: $source_vm_name");
			return;
		}
		
		notify($ERRORS{'DEBUG'}, 0, "cloning VM: $source_vm_name --> $clone_vm_name");
		my $clone_vm = $source_vm_view->CloneVM(
			folder => $folder_view,
			name => $clone_vm_name,
			spec => $clone_spec
		);
		if ($clone_vm) {
			$clone_vm_view = Vim::get_view(mo_ref => $clone_vm);
			notify($ERRORS{'DEBUG'}, 0, "cloned VM: $source_vm_name --> $clone_vm_name" . format_data($clone_vm_view));
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to clone VM: $source_vm_name --> $clone_vm_name");
			return;
		}
	};
	
	# Check if an error occurred
	if (my $fault = $@) {
		if ($fault =~ /No space left/i) {
			# Check if the output indicates there is not enough space to copy the vmdk
			# Output will contain:
			#    Fault string: A general system error occurred: No space left on device
			#    Fault detail: SystemError
			notify($ERRORS{'CRITICAL'}, 0, "failed to copy vmdk on VM host $vmhost_name, no space is left on the destination device: '$destination_path'\nerror:\n$fault");
			return;
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to copy vmdk on VM host $vmhost_name: '$source_path' --> '$destination_path'\nerror:\n$fault");
		}
		return;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "deleting source VM: $source_vm_name");
	$self->vm_unregister($source_vm_view);
	notify($ERRORS{'DEBUG'}, 0, "deleting source VM directory: $source_vm_directory_path");
	$self->delete_file($source_vm_directory_path);
	
	notify($ERRORS{'DEBUG'}, 0, "deleting cloned VM: $clone_vm_name");
	$self->vm_unregister($clone_vm_view);
	my @clone_files = $self->find_files($clone_vm_directory_path, '*', 1);
	for my $clone_file_path (grep(!/\.(vmdk)$/i, @clone_files)) {
		notify($ERRORS{'DEBUG'}, 0, "deleting cloned VM file: $clone_file_path");
		$self->delete_file($clone_file_path);
	}
	
    # Set this as a class value so that it is retrievable from within 
    # the calling context, i.e. capture(), routine. This way, in case 
    # the name changes, it is possible to update the database with the new value.
    $self->{new_image_name} = $clone_vm_name;
	notify($ERRORS{'OK'}, 0, "copied virtual disk on VM host $vmhost_name: '$source_path' --> '$destination_path'");
	return 1;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 move_virtual_disk

 Parameters  : $source_path, $destination_path
 Returns     : boolean
 Description : Moves or renames a virtual disk (set of vmdk files).

=cut

sub move_virtual_disk {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the source path argument in datastore path format
	my $source_path = $self->_get_datastore_path(shift) || return;
	my $destination_path = $self->_get_datastore_path(shift) || return;
	
	my $vmhost_name = $self->data->get_vmhost_hostname();
	
	# Make sure the source path ends with .vmdk
	if ($source_path !~ /\.vmdk$/i || $destination_path !~ /\.vmdk$/i) {
		notify($ERRORS{'WARNING'}, 0, "source and destination path arguments must end with .vmdk:\nsource path argument: $source_path\ndestination path argument: $destination_path");
		return;
	}
	
	# Make sure the source file exists
	if (!$self->file_exists($source_path)) {
		notify($ERRORS{'WARNING'}, 0, "source file does not exist on VM host $vmhost_name: '$source_path'");
		return;
	}
	
	# Make sure the destination file does not exist
	if ($self->file_exists($destination_path)) {
		notify($ERRORS{'WARNING'}, 0, "destination file already exists on VM host $vmhost_name: '$destination_path'");
		return;
	}
	
	# Get the destination parent directory path, make sure it exists
	my $destination_parent_directory_path = $self->_get_parent_directory_datastore_path($destination_path) || return;
	$self->create_directory($destination_parent_directory_path) || return;
	
	# Check if a virtual disk manager object is available
	my $virtual_disk_manager = $self->_get_virtual_disk_manager_view() || return;
	
	# Create a datacenter object
	my $datacenter = $self->_get_datacenter_view() || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	# Attempt to move the virtual disk using MoveVirtualDisk
	notify($ERRORS{'DEBUG'}, 0, "attempting to move virtual disk on VM host $vmhost_name: '$source_path' --> '$destination_path'");
	eval { $virtual_disk_manager->MoveVirtualDisk(sourceName => $source_path,
																 sourceDatacenter => $datacenter,
																 destName => $destination_path,
																 destDatacenter => $datacenter,
																 force => 0);
	};
	
	# Check if an error occurred
	if (my $fault = $@) {
		# Get the source file info
		my $source_file_info = $self->_get_file_info($source_path)->{$source_path};
		
		# A FileNotFound fault will be generated if the source vmdk file exists but there is a problem with it
		if ($fault->isa('SoapFault') && ref($fault->detail) eq 'FileNotFound' && defined($source_file_info->{type}) && $source_file_info->{type} !~ /vmdisk/i) {
			notify($ERRORS{'WARNING'}, 0, "failed to move virtual disk on VM host $vmhost_name, source file is either not a virtual disk file or there is a problem with its configuration, check the 'Extent description' section of the vmdk file: '$source_path'\nsource file info:\n" . format_data($source_file_info));
            return;
		}
		elsif ($fault =~ /No space left/i) {
			notify($ERRORS{'CRITICAL'}, 0, "failed to move virtual disk on VM host $vmhost_name, no space is left on the destination device: '$destination_path'\nerror:\n$fault");
            return;
		}
        elsif ($fault =~ /not implemented/i){
            notify($ERRORS{'DEBUG'}, 0, "unable to move vmdk using MoveVirtualDisk function, VM host $vmhost_name does not implement the MoveVirtualDisk function");
            $self->delete_file($destination_parent_directory_path);
        }
		elsif ($source_file_info) {
			notify($ERRORS{'WARNING'}, 0, "failed to move virtual disk on VM host $vmhost_name:\n'$source_path' --> '$destination_path'\nsource file info:\n" . format_data($source_file_info) . "\n$fault");
            return;
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to move virtual disk on VM host $vmhost_name:\n'$source_path' --> '$destination_path'\nsource file info: unavailable\n$fault");
            return;
		}
		
	} else {
        notify($ERRORS{'OK'}, 0, "moved virtual disk on VM host $vmhost_name:\n'$source_path' --> '$destination_path'");
        return 1;
    }
    
    # This section should apply only to vCenter hosts, i.e. hosts for which the
    # MoveVirtualDisk method is not implemented. Instead, use the copy_virtual_disk
    # method (where the CloneVM method is used) and cleanup source files afterward. 
    if($self->copy_virtual_disk($source_path, $destination_path)){
        my $file_manager = $self->_get_file_manager_view() || return;
        my $source_parent_directory_path = $self->_get_parent_directory_datastore_path($source_path) || return;
        notify($ERRORS{'DEBUG'}, 0, "Removing source directory: $source_parent_directory_path");
        $file_manager->DeleteDatastoreFile(
                    name => $source_parent_directory_path,
                    datacenter => $datacenter);
        
        notify($ERRORS{'OK'}, 0, "moved virtual disk on VM host $vmhost_name:\n'$source_path' --> '$destination_path'");
        return 1; 
    } else {
        notify($ERRORS{'WARNING'}, 0, "Unable to move virtual disk from $vmhost_name: '$source_path' --> '$destination_path'");
        return 0;
    }
}

#/////////////////////////////////////////////////////////////////////////////

=head2 create_nfs_datastore

 Parameters  : $datastore_name, $remote_host, $remote_path
 Returns     : boolean
 Description : Creates an NFS datastore on the VM host. Note: this subroutine is
               not currenly being called by anything.

=cut

sub create_nfs_datastore {
	my $self = shift;
	if (ref($self) !~ /module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the arguments
	my ($datastore_name, $remote_host, $remote_path) = @_;
	if (!$datastore_name || !$remote_host || !$remote_path) {
		notify($ERRORS{'WARNING'}, 0, "datastore name, remote host, and remote path arguments were not supplied");
		return;
	}
	
	# Remove trailing slashes from the remote path
	$remote_path =~ s/\/+$//g;
	
	# Assemble a datastore device string, used to check if existing datastore is pointing to the same remote host and path
	my $datastore_device = "$remote_host:$remote_path";
	
	# Get the existing datastore info
	my $datastore_info = $self->_get_datastore_info();
	for my $check_datastore_name (keys(%$datastore_info)) {
		my $check_datastore_type = $datastore_info->{$check_datastore_name}{type};
		
		# Make sure a non-NFS datastore with the same name doesn't alreay exist
		if ($check_datastore_type !~ /nfs/i) {
			if ($check_datastore_name eq $datastore_name) {
				notify($ERRORS{'WARNING'}, 0, "datastore named $datastore_name already exists on VM host but its type is not NFS:\n" . format_data($datastore_info->{$check_datastore_name}));
				return;
			}
			else {
				# Type isn't NFS and name doesn't match
				next;
			}
		}
		
		# Get the existing datastore device string, format is:
		# 10.25.0.245:/install/vmtest/datastore
		my $check_datastore_device = $datastore_info->{$check_datastore_name}{datastore}{value};
		if (!$check_datastore_device) {
			notify($ERRORS{'WARNING'}, 0, "unable to retrieve datastore device string from datastore info:\n" . format_data($datastore_info->{$check_datastore_name}));
			next;
		}
		
		# Remove trailing slashes from existing device string
		$check_datastore_device =~ s/\/+$//g;
		
		# Check if datastore already exists pointing to the same remote path
		if ($check_datastore_name eq $datastore_name) {
			# Datastore names match, check if existing datastore is pointing the the requested device path
			if ($check_datastore_device eq $datastore_device) {
				notify($ERRORS{'DEBUG'}, 0, "$check_datastore_type datastore '$datastore_name' already exists on VM host, remote path: $check_datastore_device");
				return 1;
			}
			else {
				notify($ERRORS{'WARNING'}, 0, "$check_datastore_type datastore '$datastore_name' already exists on VM host but it is pointing to a different remote path:
						 requested remote path: $datastore_device
						 existing remote path: $check_datastore_device");
				return;
			}
		}
		else {
			# Datastore names don't match, make sure an existing datastore with a different name isn't pointing to the requested device path
			if ($check_datastore_device eq $datastore_device) {
				notify($ERRORS{'WARNING'}, 0, "$check_datastore_type datastore with a different name already exists on VM host pointing to '$check_datastore_device':
						 requested datastore name: $datastore_name
						 existing datastore name: $check_datastore_name");
				return;
			}
			else {
				# Datastore name doesn't match, datastore remote path doesn't match
				next;
			}
		}
	}
	
	# Get the datastore system object
	my $datastore_system = Vim::get_view(mo_ref => $self->_get_datastore_view->configManager->datastoreSystem);
	if (!$datastore_system) {
		notify($ERRORS{'WARNING'}, 0, "failed to retrieve datastore system object");
		return;
	}
	
	# Create a HostNasVolumeSpec object to store the datastore configuration
	my $host_nas_volume_spec = HostNasVolumeSpec->new(accessMode => 'readWrite',
																	  localPath => $datastore_name,
																	  remoteHost => $remote_host,
																	  remotePath => $remote_path,
																	  );
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	# Attempt to cretae the NAS datastore
	notify($ERRORS{'DEBUG'}, 0, "attempting to create NAS datastore:\n" . format_data($host_nas_volume_spec));
	eval { $datastore_system->CreateNasDatastore(spec => $host_nas_volume_spec); };
	if (my $fault = $@) {
		notify($ERRORS{'WARNING'}, 0, "failed to create NAS datastore on VM host:\ndatastore name: $datastore_name\nremote host: $remote_host\nremote path: $remote_path\nerror:\n$@");
		return;
	}
	
	notify($ERRORS{'OK'}, 0, "created NAS datastore on VM host: $datastore_name");
	return 1;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_virtual_disk_controller_type

 Parameters  : $vmdk_file_path
 Returns     : string
 Description : Retrieves the disk controller type configured for the virtual
               disk specified by the vmdk file path argument. A string is
               returned containing one of the following values:
               -lsiLogic
               -busLogic
               -ide

=cut

sub get_virtual_disk_controller_type {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmdk file path argument
	my $vmdk_file_path = $self->_get_datastore_path(shift) || return;
	if ($vmdk_file_path !~ /\.vmdk$/) {
		notify($ERRORS{'WARNING'}, 0, "file path argument must end with .vmdk: $vmdk_file_path");
		return;
	}
	
	# Get the vmdk file info
	my $vmdk_file_info = $self->_get_file_info($vmdk_file_path)->{$vmdk_file_path};
	if (!$vmdk_file_info) {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve info for file: $vmdk_file_path");
		return;
	}
	
	# Check if the controllerType key exists in the vmdk file info
	if (!defined($vmdk_file_info->{controllerType}) || !$vmdk_file_info->{controllerType}) {
		notify($ERRORS{'DEBUG'}, 0, "unable to retrieve controllerType value from file info: $vmdk_file_path\n" . format_data($vmdk_file_info));
		return;
	}
	
	my $controller_type = $vmdk_file_info->{controllerType};
	
	my $return_controller_type;
	if ($controller_type =~ /lsi/i) {
		$return_controller_type = 'lsiLogic';
	}
	elsif ($controller_type =~ /bus/i) {
		$return_controller_type = 'busLogic';
	}
	elsif ($controller_type =~ /ide/i) {
		$return_controller_type = 'ide';
	}
	else {
		$return_controller_type = $controller_type;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "retrieved controllerType value from vmdk file info: $return_controller_type ($controller_type)");
	return $return_controller_type;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_virtual_disk_type

 Parameters  : $vmdk_file_path
 Returns     : string
 Description : Retrieves the disk type configured for the virtual
               disk specified by the vmdk file path argument. A string is
               returned containing one of the following values:
               -FlatVer1
               -FlatVer2
               -RawDiskMappingVer1
               -SparseVer1
               -SparseVer2

=cut

sub get_virtual_disk_type {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmdk file path argument
	my $vmdk_file_path = $self->_get_datastore_path(shift) || return;
	if ($vmdk_file_path !~ /\.vmdk$/) {
		notify($ERRORS{'WARNING'}, 0, "file path argument must end with .vmdk: $vmdk_file_path");
		return;
	}
	
	# Get the vmdk file info
	my $vmdk_file_info = $self->_get_file_info($vmdk_file_path)->{$vmdk_file_path};
	if (!$vmdk_file_info) {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve info for file: $vmdk_file_path");
		return;
	}
	
	# Check if the diskType key exists in the vmdk file info
	if (!defined($vmdk_file_info->{diskType}) || !$vmdk_file_info->{diskType}) {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve diskType value from file info: $vmdk_file_path\n" . format_data($vmdk_file_info));
		return;
	}
	
	my $disk_type = $vmdk_file_info->{diskType};
	
	if ($disk_type =~ /VirtualDisk(.+)BackingInfo/) {
		$disk_type = $1;
	}
	notify($ERRORS{'DEBUG'}, 0, "retrieved diskType value from vmdk file info: $disk_type");
	return $disk_type;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_virtual_disk_hardware_version

 Parameters  : $vmdk_file_path
 Returns     : string
 Description : Retrieves the virtual disk hardware version configured for the
               virtual disk specified by the vmdk file path argument.

=cut

sub get_virtual_disk_hardware_version {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmdk file path argument
	my $vmdk_file_path = $self->_get_datastore_path(shift) || return;
	if ($vmdk_file_path !~ /\.vmdk$/) {
		notify($ERRORS{'WARNING'}, 0, "file path argument must end with .vmdk: $vmdk_file_path");
		return;
	}
	
	# Get the vmdk file info
	my $vmdk_file_info = $self->_get_file_info($vmdk_file_path)->{$vmdk_file_path};
	if (!$vmdk_file_info) {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve info for file: $vmdk_file_path");
		return;
	}
	
	# Check if the hardwareVersion key exists in the vmdk file info
	my $hardware_version = $vmdk_file_info->{hardwareVersion};
	if (!$hardware_version) {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve hardwareVersion value from file info: $vmdk_file_path\n" . format_data($vmdk_file_info));
		return;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "retrieved hardwareVersion value from vmdk file info: $hardware_version");
	return $hardware_version;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_vmware_product_name

 Parameters  : none
 Returns     : string
 Description : Returns the full VMware product name installed on the VM host.
               Examples:
					VMware Server 2.0.2 build-203138
					VMware ESXi 4.0.0 build-208167

=cut

sub get_vmware_product_name {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{product_name} if $self->{product_name};
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	my $service_content = Vim::get_service_content();
	my $product_name = $service_content->{about}->{fullName};
	if ($product_name) {
		notify($ERRORS{'DEBUG'}, 0, "VMware product being used on VM host $vmhost_hostname: '$product_name'");
		$self->{product_name} = $product_name;
		return $self->{product_name};
	}
	else {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve VMware product name being used on VM host $vmhost_hostname");
		return;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_vmware_product_version

 Parameters  : none
 Returns     : string
 Description : Returns the VMware product version installed on the VM host.
               Example: '4.0.0'

=cut

sub get_vmware_product_version {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{product_version} if $self->{product_version};
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	my $datacenter_view = $self->_get_datacenter_view();
	my $product_version = $datacenter_view->config->product->version;
	
	if ($product_version) {
		notify($ERRORS{'DEBUG'}, 0, "retrieved product version for VM host $vmhost_hostname: $product_version");
		$self->{product_version} = $product_version;
		return $self->{product_version};
	}
	else {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve product version for VM host $vmhost_hostname");
		return;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_network_names

 Parameters  : none
 Returns     : array
 Description : Retrieves the network names configured on the VM host.

=cut

sub get_network_names {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	my $datacenter_view = $self->_get_datacenter_view();
	
	# Retrieve the network info, check if each network is accessible
	my @network_names;
	for my $network (@{Vim::get_views(mo_ref_array => $datacenter_view->network)}) {
		push @network_names, $network->name;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "retrieved network names:\n" . join("\n", @network_names));
	return @network_names;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 is_restricted

 Parameters  : none
 Returns     : boolean
 Description : Determines if remote access to the VM host via the vSphere SDK is
               restricted due to the type of VMware license being used on the
               host. 0 is returned if remote access is not restricted. 1 is
               returned if remote access is restricted and the access to the VM
               host is read-only.

=cut

sub is_restricted {
	my $self = shift;
	if (ref($self) !~ /module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	my $service_content = Vim::get_service_content();
	if (!$service_content) {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve vSphere SDK service content object, assuming access to the VM host via the vSphere SDK is restricted");
		return 1;
	}
	
	# Attempt to get a virtual disk manager object
	# This is required to copy virtual disks and perform other operations
	if (!$service_content->{virtualDiskManager}) {
		notify($ERRORS{'OK'}, 0, "access to the VM host is restricted, virtual disk manager is not available through the vSphere SDK");
		return 1;
	}
	
	# Get a fileManager object
	my $file_manager = Vim::get_view(mo_ref => $service_content->{fileManager}) || return;
	if (!$file_manager) {
		notify($ERRORS{'WARNING'}, 0, "unable to determine if access to the VM host via the vSphere SDK is restricted due to the license, failed to retrieve file manager object");
		return 1;
	}
	
	# Override the die handler because MakeDirectory may call it
	local $SIG{__DIE__} = sub{};

	# Attempt to create the test directory, check if RestrictedVersion fault occurs
	eval { $file_manager->DeleteDatastoreFile(name => ''); } ;
	if (my $fault = $@) {
		if ($fault->isa('SoapFault') && ref($fault->detail) eq 'RestrictedVersion') {
			notify($ERRORS{'OK'}, 0, "access to the VM host via the vSphere SDK is restricted due to the license: " . $fault->name);
			return 1;
		}
		elsif ($fault->isa('SoapFault') && (ref($fault->detail) eq 'InvalidDatastorePath' || ref($fault->detail) eq 'InvalidArgument')) {
			# Do nothing, expected since empty path was passed to DeleteDatastoreFile
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to determine if access to the VM host via the vSphere SDK is restricted due to the license, error:\n$@");
			return 1;
		}
	}
	
	notify($ERRORS{'OK'}, 0, "access to the VM host via the vSphere SDK is NOT restricted due to the license");
	
	return 0;
}

##############################################################################

=head1 OS FUNCTIONALITY OBJECT METHODS

=cut

#/////////////////////////////////////////////////////////////////////////////

=head2 create_directory

 Parameters  : $directory_path
 Returns     : boolean
 Description : Creates a directory on a datastore on the VM host using the
               vSphere SDK.

=cut

sub create_directory {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get and check the directory path argument
	my $directory_path = $self->_get_datastore_path(shift) || return;
	
	# Check if the directory already exists
	return 1 if $self->file_exists($directory_path);
	
	# Get the VM host name
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	# Get a fileManager object
	my $file_manager = $self->_get_file_manager_view() || return;
	
	# Override the die handler because MakeDirectory may call it
	local $SIG{__DIE__} = sub{};

	# Attempt to create the directory
	eval { $file_manager->MakeDirectory(name => $directory_path,
													datacenter => $self->_get_datacenter_view(),
													createParentDirectories => 1);
	};
	
	if ($@) {
		if ($@->isa('SoapFault') && ref($@->detail) eq 'FileAlreadyExists') {
			notify($ERRORS{'DEBUG'}, 0, "directory already exists: '$directory_path'");
			return 1;
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to create directory: '$directory_path'\nerror:\n$@");
			return;
		}
	}
	else {
		notify($ERRORS{'OK'}, 0, "created directory: '$directory_path'");
		return 1;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 delete_file

 Parameters  : $file_path
 Returns     : boolean
 Description : Deletes the file from a datastore on the VM host. Wildcards may
               not be used in the file path argument.

=cut

sub delete_file {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get and check the file path argument
	my $path_argument = shift;
	if (!$path_argument) {
		notify($ERRORS{'WARNING'}, 0, "path argument was not specified");
		return;
	}
	
	my $datastore_path = $self->_get_datastore_path($path_argument);
	if (!$datastore_path) {
		notify($ERRORS{'WARNING'}, 0, "failed to convert path argument to datastore path: $path_argument");
		return;
	}
	
	# Sanity check, make sure the file path argument is not the root of a datastore
	# Otherwise everything in the datastore would be deleted
	if ($datastore_path =~ /^\[.+\]$/) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called with the file path argument pointing to the root of a datastore, this would cause all datastore contents to be deleted\nfile path argument: '$path_argument'\ndatastore path: '$datastore_path'");
		return;
	}
	
	# Get a fileManager object
	my $file_manager = $self->_get_file_manager_view() || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};

	# Attempt to delete the file
	notify($ERRORS{'OK'}, 0, "attempting to delete file: $datastore_path");
	eval { $file_manager->DeleteDatastoreFile(name => $datastore_path, datacenter => $self->_get_datacenter_view()); };
	if ($@) {
		if ($@->isa('SoapFault') && ref($@->detail) eq 'FileNotFound') {
			notify($ERRORS{'DEBUG'}, 0, "file does not exist: $datastore_path");
			return 1;
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to delete file: $datastore_path, error:\n$@");
			return;
		}
	}
	else {
		notify($ERRORS{'OK'}, 0, "deleted file: $datastore_path");
		return 1;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 copy_file

 Parameters  : $source_file_path, $destination_file_path
 Returns     : boolean
 Description : Copies a file from one datastore location on the VM host to
               another datastore location on the VM host. Wildcards may not be
               used in the file path argument.

=cut

sub copy_file {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get and check the file path arguments
	my $source_file_path = $self->_get_datastore_path(shift) || return;
	my $destination_file_path = $self->_get_datastore_path(shift) || return;
	
	# Get the VM host name
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	# Get the destination directory path and create the directory if it doesn't exit
	my $destination_directory_path = $self->_get_parent_directory_datastore_path($destination_file_path) || return;
	$self->create_directory($destination_directory_path) || return;
	
	# Get a fileManager object
	my $file_manager = $self->_get_file_manager_view() || return;
	my $datacenter = $self->_get_datacenter_view() || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	# Attempt to copy the file
	notify($ERRORS{'DEBUG'}, 0, "attempting to copy file on VM host $vmhost_hostname: '$source_file_path' --> '$destination_file_path'");
	eval { $file_manager->CopyDatastoreFile(sourceName => $source_file_path,
														 sourceDatacenter => $datacenter,
														 destinationName => $destination_file_path,
														 destinationDatacenter => $datacenter,
														 force => 0);
	};
	
	# Check if an error occurred
	if ($@) {
		if ($@->isa('SoapFault') && ref($@->detail) eq 'FileNotFound') {
			notify($ERRORS{'WARNING'}, 0, "source file does not exist on VM host $vmhost_hostname: '$source_file_path'");
			return 0;
		}
		elsif ($@->isa('SoapFault') && ref($@->detail) eq 'FileAlreadyExists') {
			notify($ERRORS{'WARNING'}, 0, "destination file already exists on VM host $vmhost_hostname: '$destination_file_path'");
			return 0;
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to copy file on VM host $vmhost_hostname: '$source_file_path' --> '$destination_file_path'\nerror:\n$@");
			return;
		}
	}
	
	notify($ERRORS{'OK'}, 0, "copied file on VM host $vmhost_hostname: '$source_file_path' --> '$destination_file_path'");
	return 1;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 copy_file_to

 Parameters  : $source_file_path, $destination_file_path
 Returns     : boolean
 Description : Copies a file from the management node to a datastore on the VM
               host. The complete source and destination file paths must be
               specified. Wildcards may not be used.

=cut

sub copy_file_to {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the source and destination arguments
	my $source_file_path = normalize_file_path(shift) || return;
	my $destination_file_path = $self->_get_datastore_path(shift) || return;
	
	# Make sure the source file exists on the management node
	if (!-f $source_file_path) {
		notify($ERRORS{'WARNING'}, 0, "source file does not exist on the management node: '$source_file_path'");
		return;
	}
	
	# Make sure the destination directory path exists
	my $destination_directory_path = $self->_get_parent_directory_datastore_path($destination_file_path) || return;
	$self->create_directory($destination_directory_path) || return;
	sleep 2;
	
	# Get the VM host name
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	my $datacenter_name = $self->_get_datacenter_name();
	
	# Get the destination datastore name and relative datastore path
	my $destination_datastore_name = $self->_get_datastore_name($destination_file_path);
	my $destination_relative_datastore_path = $self->_get_relative_datastore_path($destination_file_path);
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	# Attempt to copy the file -- make a few attempts since this can sometimes fail
    return $self->code_loop_timeout(
        sub{
            my $response;
            eval { $response = VIExt::http_put_file("folder" , $source_file_path, $destination_relative_datastore_path, $destination_datastore_name, $datacenter_name); };
            if ($response->is_success) {
                notify($ERRORS{'DEBUG'}, 0, "copied file from management node to VM host: '$source_file_path' --> $vmhost_hostname:'[$destination_datastore_name] $destination_relative_datastore_path'");
                return 1;
            }
            else {
                notify($ERRORS{'WARNING'}, 0, "failed to copy file from management node to VM host: '$source_file_path' --> $vmhost_hostname($datacenter_name):'$destination_file_path'\nerror: " . $response->message);
                return;
            }
        }, [], "attempting to copy file from management node to VM host: '$source_file_path' --> $vmhost_hostname:'[$destination_datastore_name] $destination_relative_datastore_path'", 50, 5);

}

#/////////////////////////////////////////////////////////////////////////////

=head2 copy_file_from

 Parameters  : $source_file_path, $destination_file_path
 Returns     : boolean
 Description : Copies file from a datastore on the VM host to the management
               node. The complete source and destination file paths must be
               specified. Wildcards may not be used.

=cut

sub copy_file_from {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the source and destination arguments
	my $source_file_path = $self->_get_datastore_path(shift) || return;
	my $destination_file_path = normalize_file_path(shift) || return;
	
	# Get the destination directory path and make sure the directory exists
	my $destination_directory_path = $self->_get_parent_directory_normal_path($destination_file_path) || return;
	if (!-d $destination_directory_path) {
		# Attempt to create the directory
		my $command = "mkdir -p -v \"$destination_directory_path\" 2>&1 && ls -1d \"$destination_directory_path\"";
		my ($exit_status, $output) = run_command($command, 1);
		if (!defined($output)) {
			notify($ERRORS{'WARNING'}, 0, "failed to run command to create directory on management node: '$destination_directory_path'\ncommand: '$command'");
			return;
		}
		elsif (grep(/created directory/i, @$output)) {
			notify($ERRORS{'OK'}, 0, "created directory on management node: '$destination_directory_path'");
		}
		elsif (grep(/mkdir: /i, @$output)) {
			notify($ERRORS{'WARNING'}, 0, "error occurred attempting to create directory on management node: '$destination_directory_path':\ncommand: '$command'\nexit status: $exit_status\noutput:\n" . join("\n", @$output));
			return;
		}
		elsif (grep(/^$destination_directory_path/, @$output)) {
			notify($ERRORS{'OK'}, 0, "directory already exists on management node: '$destination_directory_path'");
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "unexpected output returned from command to create directory on management node: '$destination_directory_path':\ncommand: '$command'\nexit status: $exit_status\noutput:\n" . join("\n", @$output));
			return;
		}
	}
	
	# Get the VM host name
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	my $datacenter_name = $self->_get_datacenter_name();
	
	# Get the source datastore name
	my $source_datastore_name = $self->_get_datastore_name($source_file_path) || return;
	
	# Get the source file relative datastore path
	my $source_file_relative_datastore_path = $self->_get_relative_datastore_path($source_file_path) || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
    # Attempt to copy the file -- make a few attempts since this can sometimes fail
    return $self->code_loop_timeout(
        sub{
            my $response;
            eval { $response = VIExt::http_get_file("folder", $source_file_relative_datastore_path, $source_datastore_name, $datacenter_name, $destination_file_path); };
            if ($response->is_success) {
                notify($ERRORS{'DEBUG'}, 0, "copied file from VM host to management node: $vmhost_hostname:'[$source_datastore_name] $source_file_relative_datastore_path' --> '$destination_file_path'");
                return 1;
            }
            else {
                notify($ERRORS{'WARNING'}, 0, "failed to copy file from VM host to management node: $vmhost_hostname:'[$source_datastore_name] $source_file_relative_datastore_path' --> '$destination_file_path'\nerror: " . $response->message);
                return;
            }
        }, [], "attempting to copy file from VM host to management node:  $vmhost_hostname:'[$source_datastore_name] $source_file_relative_datastore_path' --> '$destination_file_path'", 50, 5);

}


#/////////////////////////////////////////////////////////////////////////////

=head2 get_file_contents

 Parameters  : $file_path
 Returns     : array
 Description : Returns an array containing the contents of the file on the VM
               host specified by the file path argument. Each array element
               contains a line in the file.

=cut

sub get_file_contents {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# TODO: add file size check before retrieving file in case file is huge
	
	# Get the source and destination arguments
	my ($path) = shift;
	if (!$path) {
		notify($ERRORS{'WARNING'}, 0, "file path argument was not specified");
		return;
	}
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	# Create a temp directory to store the file and construct the temp file path
	# The temp directory is automatically deleted then this variable goes out of scope
	my $temp_directory_path = tempdir( CLEANUP => 1 );
	my $source_file_name = $self->_get_file_name($path);
	my $temp_file_path = "$temp_directory_path/$source_file_name";
	
	$self->copy_file_from($path, $temp_file_path) || return;
	
	# Run cat to retrieve the contents of the file
	my $command = "cat \"$temp_file_path\"";
	my ($exit_status, $output) = VCL::utils::run_command($command, 1);
	if (!defined($output)) {
		notify($ERRORS{'WARNING'}, 0, "failed to run command to read file: '$temp_file_path'\ncommand: '$command'");
		return;
	}
	elsif (grep(/^cat: /, @$output)) {
		notify($ERRORS{'WARNING'}, 0, "failed to read contents of file: '$temp_file_path', exit status: $exit_status, output:\n" . join("\n", @$output));
		return;
	}
	else {
		notify($ERRORS{'DEBUG'}, 0, "retrieved " . scalar(@$output) . " lines from file: '$temp_file_path'");
	}
	
	# Output lines contain trailing newlines, remove them
	@$output = map { chomp; $_; } @$output;
	return @$output;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 move_file

 Parameters  : $source_path, $destination_path
 Returns     : boolean
 Description : Moves or renames a file from one datastore location on the VM
               host to another datastore location on the VM host. Wildcards may
               not be used in the file path argument.

=cut

sub move_file {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get and check the file path arguments
	my $source_file_path = $self->_get_datastore_path(shift) || return;
	my $destination_file_path = $self->_get_datastore_path(shift) || return;
	
	# Get the VM host name
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	# Get the destination directory path and create the directory if it doesn't exit
	my $destination_directory_path = $self->_get_parent_directory_datastore_path($destination_file_path) || return;
	$self->create_directory($destination_directory_path) || return;
	
	# Get a fileManager and Datacenter object
	my $file_manager = $self->_get_file_manager_view() || return;
	my $datacenter = $self->_get_datacenter_view() || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};

	# Attempt to copy the file
	notify($ERRORS{'DEBUG'}, 0, "attempting to move file on VM host $vmhost_hostname: '$source_file_path' --> '$destination_file_path'");
	eval { $file_manager->MoveDatastoreFile(sourceName => $source_file_path,
														 sourceDatacenter => $datacenter,
														 destinationName => $destination_file_path,
														 destinationDatacenter => $datacenter
														 );
	};
	
	if ($@) {
		if ($@->isa('SoapFault') && ref($@->detail) eq 'FileNotFound') {
			notify($ERRORS{'WARNING'}, 0, "source file does not exist on VM host $vmhost_hostname: '$source_file_path'");
			return 0;
		}
		elsif ($@->isa('SoapFault') && ref($@->detail) eq 'FileAlreadyExists') {
			notify($ERRORS{'WARNING'}, 0, "destination file already exists on VM host $vmhost_hostname: '$destination_file_path'");
			return 0;
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to move file on VM host $vmhost_hostname: '$source_file_path' --> '$destination_file_path', error:\n$@");
			return;
		}
	}
	
	notify($ERRORS{'OK'}, 0, "moved file on VM host $vmhost_hostname: '$source_file_path' --> '$destination_file_path'");
	return 1;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 file_exists

 Parameters  : $file_path
 Returns     : boolean
 Description : Determines if a file exists on a datastore on the VM host.

=cut

sub file_exists {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get and check the file path argument
	my $file_path = $self->_get_datastore_path(shift) || return;
	
	# Check if the path argument is the root of a datastore
	if ($file_path =~ /^\[(.+)\]$/) {
		my $datastore_name = $1;
		(my @datastore_names = $self->_get_datastore_names()) || return;
		
		if (grep(/^$datastore_name$/, @datastore_names)) {
			notify($ERRORS{'DEBUG'}, 0, "file (datastore root) exists: $file_path");
			return 1;
		}
		else {
			notify($ERRORS{'DEBUG'}, 0, "file (datastore root) does not exist: $file_path, datastores on VM host:\n" . join("\n", @datastore_names));
			return 0;
		}
	}
	
	# Take the path apart, get the filename and parent directory path
	my $base_directory_path = $self->_get_parent_directory_datastore_path($file_path) || return;
	my $file_name = $self->_get_file_name($file_path) || return;
	
	my $result = $self->find_files($base_directory_path, $file_name);
	if ($result) {
		notify($ERRORS{'DEBUG'}, 0, "file exists: $file_path");
		return 1;
	}
	elsif (defined($result)) {
		notify($ERRORS{'DEBUG'}, 0, "file does not exist: $file_path");
		return 0;
	}
	else {
		notify($ERRORS{'WARNING'}, 0, "failed to determine if file exists: $file_path");
		return;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_file_size

 Parameters  : $file_path
 Returns     : integer
 Description : Determines the size of a file of a datastore in bytes. Wildcards
               may be used in the file path argument. The total size of all
               files found will be returned. Subdirectories are not searched.

=cut

sub get_file_size {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get and check the file path argument
	my $file_path_argument = shift;
	if (!$file_path_argument) {
		notify($ERRORS{'WARNING'}, 0, "file path argument was not specified");
		return;
	}
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	# Get the file info
	my $file_info = $self->_get_file_info($file_path_argument);
	if (!defined($file_info)) {
		notify($ERRORS{'WARNING'}, 0, "unable to get file size, failed to get file info for: $file_path_argument");
		return;
	}
	
	# Make sure the file info is not null or else an error occurred
	if (!$file_info) {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve info for file on $vmhost_hostname: $file_path_argument");
		return;
	}
	
	# Check if there are any keys in the file info hash - no keys indicates no files were found
	if (!keys(%{$file_info})) {
		notify($ERRORS{'DEBUG'}, 0, "unable to determine size of file on $vmhost_hostname because it does not exist: $file_path_argument");
		return;
	}
	
	# Loop through the files, add their sizes to the total
	my $total_size_bytes = 0;
	for my $file_path (keys(%{$file_info})) {
		my $file_size_bytes = $file_info->{$file_path}{fileSize};
		notify($ERRORS{'DEBUG'}, 0, "size of '$file_path': " . format_number($file_size_bytes) . " bytes");
		$total_size_bytes += $file_size_bytes;
	}
	
	my $total_size_bytes_string = format_number($total_size_bytes);
	my $total_size_mb_string = format_number(($total_size_bytes / 1024 / 1024), 2);
	my $total_size_gb_string = format_number(($total_size_bytes / 1024 / 1024 /1024), 2);
	
	notify($ERRORS{'DEBUG'}, 0, "total file size of '$file_path_argument': $total_size_bytes_string bytes ($total_size_mb_string MB, $total_size_gb_string GB)");
	return $total_size_bytes;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 find_files

 Parameters  : $base_directory_path, $search_pattern, $search_subdirectories (optional)
 Returns     : array
 Description : Finds files in a datastore on the VM host stored under the base
               directory path argument. The search pattern may contain
               wildcards. Subdirectories will be searched if the 3rd argument is
               not supplied.

=cut

sub find_files {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the arguments
	my ($base_directory_path, $search_pattern, $search_subdirectories) = @_;
	if (!$base_directory_path || !$search_pattern) {
		notify($ERRORS{'WARNING'}, 0, "base directory path and search pattern arguments were not specified");
		return;
	}
	
	$search_subdirectories = 1 if !defined($search_subdirectories);
	
	$base_directory_path = $self->_get_normal_path($base_directory_path) || return;
	
	# Get the file info
	my $file_info = $self->_get_file_info("$base_directory_path/$search_pattern", $search_subdirectories);
	if (!defined($file_info)) {
		notify($ERRORS{'WARNING'}, 0, "unable to find files, failed to get file info for: $base_directory_path/$search_pattern");
		return;
	}
	
	# Loop through the keys of the file info hash
	my @file_paths;
	for my $file_path (keys(%{$file_info})) {
		# Add the file path to the return array
		push @file_paths, $self->_get_normal_path($file_path);
		
		# vmdk files will have a diskExtents key
		# The extents must be added to the return array
		if (defined($file_info->{$file_path}->{diskExtents})) {
			for my $disk_extent (@{$file_info->{$file_path}->{diskExtents}}) {
				# Convert the datastore file paths to normal file paths
				$disk_extent = $self->_get_normal_path($disk_extent);
				push @file_paths, $self->_get_normal_path($disk_extent);
			}
		}
	}
	
	@file_paths = sort @file_paths;
	notify($ERRORS{'DEBUG'}, 0, "matching file count: " . scalar(@file_paths));
	return @file_paths;
}

#/////////////////////////////////////////////////////////////////////////////
 
=head2 get_total_space 

 Parameters  : $path 
 Returns     : integer 
 Description : Returns the total size (in bytes) of the volume specified by the
               argument. 

=cut 

sub get_total_space { 
	my $self = shift; 
	if (ref($self) !~ /VCL::Module/i) { 
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return; 
	} 
	
	# Get the path argument 
	my $path = shift; 
	if (!$path) { 
		notify($ERRORS{'WARNING'}, 0, "path argument was not specified"); 
		return; 
	} 
	
	# Get the datastore name 
	my $datastore_name = $self->_get_datastore_name($path) || return; 
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname(); 
	
	# Get the datastore info hash 
	my $datastore_info = $self->_get_datastore_info() || return; 
	
	my $total_bytes = $datastore_info->{$datastore_name}{capacity}; 
	if (!defined($total_bytes)) { 
		notify($ERRORS{'WARNING'}, 0, "datastore $datastore_name capacity key does not exist in datastore info:\n" . format_data($datastore_info));
		return; 
	} 
	
	notify($ERRORS{'DEBUG'}, 0, "capacity of $datastore_name datastore on $vmhost_hostname: " . get_file_size_info_string($total_bytes));
	return $total_bytes; 
} 


#/////////////////////////////////////////////////////////////////////////////

=head2 get_available_space

 Parameters  : $path
 Returns     : integer
 Description : Returns the bytes available in the path specified by the
               argument.

=cut

sub get_available_space {
	my $self = shift;
	if (ref($self) !~ /module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the path argument
	my $path = shift;
	if (!$path) {
		notify($ERRORS{'WARNING'}, 0, "path argument was not specified");
		return;
	}
	
	# Get the datastore name
	my $datastore_name = $self->_get_datastore_name($path) || return;
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	# Get the datastore info hash
	my $datastore_info = $self->_get_datastore_info(1) || return;
	
	my $available_bytes = $datastore_info->{$datastore_name}{freeSpace};
	if (!defined($available_bytes)) {
		notify($ERRORS{'WARNING'}, 0, "datastore $datastore_name freeSpace key does not exist in datastore info:\n" . format_data($datastore_info));
		return;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "space available in $datastore_name datastore on $vmhost_hostname: " . get_file_size_info_string($available_bytes));
	return $available_bytes;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_cpu_core_count

 Parameters  : none
 Returns     : integer
 Description : Retrieves the quantitiy of CPU cores the VM host has.

=cut

sub get_cpu_core_count {
	my $self = shift;
	if (ref($self) !~ /module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{cpu_core_count} if $self->{cpu_core_count};
	
	my $cpu_core_count;
	if (my $host_system_view = $self->_get_host_system_view()) {
		my $vmhost_hostname = $self->data->get_vmhost_hostname();
		$cpu_core_count = $host_system_view->{hardware}->{cpuInfo}->{numCpuCores};
		notify($ERRORS{'DEBUG'}, 0, "retrieved CPU core count for VM host '$vmhost_hostname': $cpu_core_count");
	}
	elsif (my $cluster = $self->_get_cluster_view()) {
		# Try to get CPU core count of cluster if cluster is being used
		my $cluster_name = $cluster->{name};
		$cpu_core_count = $cluster->{summary}->{numCpuCores};
		notify($ERRORS{'DEBUG'}, 0, "retrieved CPU core count for '$cluster_name' cluster: $cpu_core_count");
	
	}
	else {
		notify($ERRORS{'WARNING'}, 0, "unable to determine CPU core count of VM host");
		return;
	}
	
	$self->{cpu_core_count} = $cpu_core_count;
	return $self->{cpu_core_count};
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_cpu_speed

 Parameters  : none
 Returns     : integer
 Description : Retrieves the speed of the VM host's CPUs in MHz.

=cut

sub get_cpu_speed {
	my $self = shift;
	if (ref($self) !~ /module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{cpu_speed} if $self->{cpu_speed};
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	# Try to get CPU speed of resource pool
	if (my $resource_pool = $self->_get_resource_pool_view()) {
		my $resource_pool_name = $resource_pool->{name};
		
		my $mhz = $resource_pool->{runtime}{cpu}{maxUsage};
		
		# maxUsage reports sum of all CPUs - divide by core count
		# This isn't exact - will be lower than acutal clock rate of CPUs in host
		if (my $cpu_core_count = $self->get_cpu_core_count()) {
			$mhz = int($mhz / $cpu_core_count);
		}
		
		$self->{cpu_speed} = $mhz;
		notify($ERRORS{'DEBUG'}, 0, "retrieved total CPU speed of '$resource_pool_name' resource pool: $self->{cpu_speed} MHz");
		return $self->{cpu_speed};
	}
	else {
		notify($ERRORS{'WARNING'}, 0, "unable to determine CPU speed of VM host, resource pool view object could not be retrieved");
		return;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_total_memory

 Parameters  : none
 Returns     : integer
 Description : Retrieves the VM host's total memory capacity in MB.

=cut

sub get_total_memory {
	my $self = shift;
	if (ref($self) !~ /module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{total_memory} if $self->{total_memory};
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	
	# Try to get total memory of resource pool
	if (my $resource_pool = $self->_get_resource_pool_view()) {
		my $resource_pool_name = $resource_pool->{name};
		
		my $memory_bytes = $resource_pool->{runtime}{memory}{maxUsage};
		my $memory_mb = int($memory_bytes / 1024 / 1024);
		
		$self->{total_memory} = $memory_mb;
		notify($ERRORS{'DEBUG'}, 0, "retrieved total memory of '$resource_pool_name' resource pool: $self->{total_memory} MB");
		return $self->{total_memory};
	}
	else {
		notify($ERRORS{'WARNING'}, 0, "unable to determine total memory on VM host, resource pool view object could not be retrieved");
		return;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 get_license_info

 Parameters  : none
 Returns     : hash reference
 Description : Retrieves the license information from the host. A hash reference
               is returned:
               {
                 "costUnit" => "cpuPackage",
                 "editionKey" => "esxBasic.vram",
                 "licenseKey" => "XXXXX-XXXXX-XXXXX-XXXXX-XXXXX",
                 "name" => "VMware vSphere 5 Hypervisor",
                 "properties" => {
                   "FileVersion" => "5.0.0.19",
                   "LicenseFilePath" => [
                     "/usr/lib/vmware/licenses/site/license-esx-50-e03-c3-t2-201006",
                     ...
                     "/usr/lib/vmware/licenses/site/license-esx-50-e01-v1-l0-201006"
                   ],
                   "ProductName" => "VMware ESX Server",
                   "ProductVersion" => "5.0",
                   "count_disabled" => "This license is unlimited",
                   "feature" => {
                     "maxRAM:32g" => "Up to 32 GB of memory",
                     "vsmp:8" => "Up to 8-way virtual SMP"
                   },
                   "vram" => "32g"
                 },
                 "total" => 0,
                 "used" => 2
               }

=cut

sub get_license_info {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{license_info} if $self->{license_info};
	
	my $service_content = Vim::get_service_content() || return;
	my $licenses = Vim::get_view(mo_ref => $service_content->{licenseManager})->licenses;
	
	my $license_info;
	for my $license (@$licenses) {
		$license_info->{costUnit} = $license->costUnit;
		$license_info->{editionKey} = $license->editionKey;
		$license_info->{licenseKey} = $license->licenseKey;
		$license_info->{name} = $license->name;
		$license_info->{total} = $license->total;
		$license_info->{used} = $license->used;
		
		my $properties = $license->properties;
		for my $property (@$properties) {
			if ($property->key eq 'feature') {
				my $feature_name = $property->value->key;
				my $feature_description = $property->value->value;
				$license_info->{properties}{feature}{$feature_name} = $feature_description;
			}
			elsif ($property->key eq 'LicenseFilePath') {
				# Leave this out of data for now, not used anywhere, clutters display of license info
				#push @{$license_info->{properties}{LicenseFilePath}}, $property->value;
			}
			else {
				$license_info->{properties}{$property->key} = $property->value;
			}
		}
	}
	
	$self->{license_info} = $license_info;
	notify($ERRORS{'DEBUG'}, 0, "retrieved license info:\n" . format_data($license_info));
	return $license_info;
}

##############################################################################

=head1 PRIVATE OBJECT METHODS

=cut

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_file_info

 Parameters  : $file_path
 Returns     : hash reference
 Description : Retrieves information about the file stored in a datastore
               specified by the file path argument on the VM host. The file path
               argument may be a wildcard. A hash reference is returned. The
               hash keys are paths to the files found. Example of returned data:
               {[nfs-datastore] vmwarewin2008-enterprisex86_641635-v0/vmwarewin2008-enterprisex86_641635-v0.vmdk}
                  -{capacityKb} = '15728640'
                  -{controllerType} = 'VirtualLsiLogicController'
                  -{diskType} = 'VirtualDiskSparseVer2BackingInfo'
                  -{fileSize} = '7128891392'
                  -{hardwareVersion} = '4'
                  -{modification} = '2010-05-27T12:14:51Z'
                  -{owner} = 'root'
                  -{path} = 'vmwarewin2008-enterprisex86_641635-v0.vmdk'
                  -{thin} = '1'
                  -{type} = 'VmDiskFileInfo'

=cut

sub _get_file_info {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the arguments
	my ($path_argument, $search_subfolders) = @_;
	if (!$path_argument) {
		notify($ERRORS{'WARNING'}, 0, "file path argument was not specified");
		return;
	}
	
	# Take the path argument apart
	my $base_directory_path = $self->_get_parent_directory_datastore_path($path_argument) || return;
	my $search_pattern = $self->_get_file_name($path_argument) || return;
	
	# Set the default value for $search_subfolders if the argument wasn't passed
	$search_subfolders = 0 if !$search_subfolders;
	
	# Make sure the base directory path is formatted as a datastore path
	my $base_datastore_path = $self->_get_datastore_path($base_directory_path) || return;
	
	# Extract the datastore name from the base directory path
	my $datastore_name = $self->_get_datastore_name($base_directory_path) || return;
	
	# Get a datastore object and host datastore browser object
	my $datastore = $self->_get_datastore_object($datastore_name) || return;
	my $host_datastore_browser = Vim::get_view(mo_ref => $datastore->browser);
	
	# Create HostDatastoreBrowserSearchSpec spec
   my $file_query_flags = FileQueryFlags->new(
		fileOwner => 1,
		fileSize => 1,
		fileType => 1,
		modification => 1,
	);
	
	my $vm_disk_file_query_flags = VmDiskFileQueryFlags->new(
		capacityKb => 1,
		controllerType => 1,
		diskExtents => 1,
		diskType => 1,
		hardwareVersion => 1,
		thin => 1,

	);
	
	my $vm_disk_file_query = VmDiskFileQuery->new(
		details => $vm_disk_file_query_flags,
	);
	
	my @file_queries = (
		$vm_disk_file_query,
		FileQuery->new(),
		FloppyImageFileQuery->new(),
		FolderFileQuery->new(),
		IsoImageFileQuery->new(),
		VmConfigFileQuery->new(),
		VmLogFileQuery->new(),
		VmNvramFileQuery->new(),
		VmSnapshotFileQuery->new(),
	);
	
	my $hostdb_search_spec = HostDatastoreBrowserSearchSpec->new(
		details => $file_query_flags,
		matchPattern => [$search_pattern],
		searchCaseInsensitive => 0,
		sortFoldersFirst => 1,
		query => [@file_queries],
	);
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	# Searches the folder specified by the datastore path and all subfolders based on the searchSpec
	my $task;
	notify($ERRORS{'DEBUG'}, 0, "searching for matching file paths: base directory path: '$base_directory_path', search pattern: '$search_pattern'");
	if ($search_subfolders) {
		eval { $task = $host_datastore_browser->SearchDatastoreSubFolders(datastorePath=>$base_datastore_path, searchSpec=>$hostdb_search_spec); };
	}
	else {
		eval { $task = $host_datastore_browser->SearchDatastore(datastorePath=>$base_datastore_path, searchSpec=>$hostdb_search_spec); };
	}
	
	# Check if an error occurred
	if ($@) {
		if ($@->isa('SoapFault') && ref($@->detail) eq 'FileNotFound') {
			notify($ERRORS{'DEBUG'}, 0, "base directory does not exist: '$base_directory_path'");
			return {};
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "failed to search datastore to determine if file exists\nbase directory path: '$base_directory_path'\nsearch pattern: '$search_pattern'\nerror:\n$@");
			return;
		}
	}
	
	# The $task result with either be an array of scalar depending on the value of $search_subfolders
	# If $search_subfolders = 0, SearchDatastore is called and the result is a scalar
	# If $search_subfolders = 1, SearchDatastoreSubFolders is called and the result is an array
	# Convert the scalar result to an array
	my @folders;
	if (ref($task) eq 'ARRAY') {
		@folders = @{$task};
	}
	else {
		$folders[0] = $task;
	}
	
	my %file_info;
	for my $folder (sort @folders) {
		if ($folder->file) {
			# Retrieve the folder path, format: '[nfs-datastore] vmwarewinxp-base234-v12'
			my $directory_datastore_path =  $folder->folderPath;
			my $directory_normal_path = $self->_get_normal_path($directory_datastore_path);
			
			# Loop through all of the files under the folder
			foreach my $file (@{$folder->file}) {
				my $file_path = $self->_get_datastore_path("$directory_normal_path/" . $file->path);
				
				# Check the file type
				if (ref($file) eq 'FolderFileInfo') {
					# Don't include folders in the results
					next;
				}
				
				$file_info{$file_path} = $file;
				$file_info{$file_path}{type} = ref($file);
			}
		}
	}
	
	notify($ERRORS{'DEBUG'}, 0, "retrieved info for " . scalar(keys(%file_info)) . " matching files:\n" . format_data(\%file_info));
	return \%file_info;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_datacenter_view

 Parameters  : 
 Returns     : vSphere SDK Datacenter view object
 Description : Retrieves a vSphere SDK Datacenter view object.

=cut

sub _get_datacenter_view {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{datacenter_view_object} if $self->{datacenter_view_object};
	
	my $vmhost_name = $self->data->get_vmhost_short_name();
	
	my $datacenter;
	
	# Get the resource pool view - attempt to get the parent datacenter of the resource pool
	my $resource_pool = $self->_get_resource_pool_view();
	if ($resource_pool) {
		$datacenter = $self->_get_parent_managed_object_view($resource_pool, 'Datacenter');
	}
	
	if (!$datacenter) {
		# Unable to get parent datacenter of resource view, get all datacenter views
		# Return datacenter view only if 1 datacenter was retrieved
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve parent datacenter for resource pool object");
		
		my @datacenters = @{Vim::find_entity_views(view_type => 'Datacenter')};
		if (!scalar(@datacenters)) {
			notify($ERRORS{'WARNING'}, 0, "failed to retrieve Datacenter view from VM host $vmhost_name");
			return;
		}
		elsif (scalar(@datacenters) > 1) {
			notify($ERRORS{'WARNING'}, 0, "unable to determine correct Datacenter to use, multiple Datacenter views were found on VM host $vmhost_name");
			return;
		}
		else {
			$datacenter = $datacenters[0];
		}
	}
	
	my $datacenter_name = $datacenter->{name};
	notify($ERRORS{'DEBUG'}, 0, "found datacenter VM host on $vmhost_name: $datacenter_name");
	
	$self->{datacenter_view_object} = $datacenter;
	return $self->{datacenter_view_object};
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_datacenter_name

 Parameters  : 
 Returns     : 
 Description : 

=cut

sub _get_datacenter_name {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	my $datacenter_view_object = $self->_get_datacenter_view() || return;
	return $datacenter_view_object->{name};
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_cluster_view

 Parameters  : 
 Returns     : vSphere SDK ClusterComputeResource view object
 Description : Retrieves a vSphere SDK ClusterComputeResource view object.

=cut

sub _get_cluster_view {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{cluster_view_object} if $self->{cluster_view_object};
	
	my $vmhost_name = $self->data->get_vmhost_short_name();
	
	my $resource_pool = $self->_get_resource_pool_view() || return;

	my $cluster = $self->_get_parent_managed_object_view($resource_pool, 'ClusterComputeResource');
	if (!$cluster) {
		notify($ERRORS{'WARNING'}, 0, "unable to retrieve cluster view object");
		return;
	}
	
	my $cluster_name = $cluster->{name};
	notify($ERRORS{'DEBUG'}, 0, "retrieved '$cluster_name' cluster view");
	
	$self->{cluster_view_object} = $cluster;
	return $self->{cluster_view_object};
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_host_system_view

 Parameters  : 
 Returns     : vSphere SDK HostSystem view object
 Description : Retrieves a vSphere SDK HostSystem view object.

=cut

sub _get_host_system_view {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{host_system_view_object} if $self->{host_system_view_object};
	
	## Check if host is using vCenter - can only retrieve HostSystem view for standalone hosts
	#if ($self->_is_vcenter()) {
	#	notify($ERRORS{'DEBUG'}, 0, "HostSystem view cannot be retrieved for vCenter host");
	#	return;
	#}
	
	my $vmhost_name = $self->data->get_vmhost_short_name();
	
	my @host_system_views = @{Vim::find_entity_views(view_type => 'HostSystem')};
	if (!scalar(@host_system_views)) {
		notify($ERRORS{'WARNING'}, 0, "failed to retrieve HostSystem views");
		return;
	}
	elsif (scalar(@host_system_views) == 1) {
		$self->{host_system_view_object} = $host_system_views[0];
		return $self->{host_system_view_object};
	}
	
	my @host_system_names;
	for my $host_system_view (@host_system_views) {
		my $host_system_name = $host_system_view->{name};
		push @host_system_names, $host_system_name;
		
		if ($host_system_name =~ /^$vmhost_name(\.|$)/i || $host_system_name =~ /^$vmhost_name(\.|$)/i) {
			notify($ERRORS{'DEBUG'}, 0, "retrieved matching HostSystem view: '$host_system_name', VCL VM host name: '$vmhost_name'");
			$self->{host_system_view_object} = $host_system_view;
			return $self->{host_system_view_object};
		}
		else {
			notify($ERRORS{'DEBUG'}, 0, "name of HostSystem '$host_system_name' does NOT match VCL VM host name: '$vmhost_name'");
		}
	}
	
	return $host_system_views[0];
	notify($ERRORS{'WARNING'}, 0, "did not find a HostSystem view with a name matching the VCL VM host name: '$vmhost_name', HostSystem names:\n" . join("\n", @host_system_names));
	return;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_resource_pool_view

 Parameters  : 
 Returns     : vSphere SDK ResourcePool view object
 Description : Retrieves a vSphere SDK ResourcePool view object.

=cut

sub _get_resource_pool_view {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{resource_pool_view_object} if $self->{resource_pool_view_object};
	
	my $vmhost_name = $self->data->get_vmhost_short_name();
	
	# Get the resource path from the VM host profile if it is configured
	my $vmhost_profile_resource_path = $self->data->get_vmhost_profile_resource_path(0);
	
	# Retrieve all of the ResourcePool views on the VM host
	my @resource_pools = @{Vim::find_entity_views(view_type => 'ResourcePool')};
	if (!@resource_pools) {
		notify($ERRORS{'WARNING'}, 0, "failed to retrieve any resource pool views from VM host $vmhost_name");
		return;
	}
	
	my @resource_pool_paths;
	my %potential_matches;
	for my $resource_pool (@resource_pools) {
		# Assemble the full path to the resource view - including Datacenters, folders, clusters...
		my $resource_pool_path = $self->_get_managed_object_path($resource_pool->{mo_ref});
		
		# The path of the resource pool retrieved from the VM host will contain levels which don't appear in vCenter
		# For example, 'host' and 'Resources' don't appear in the tree view:
		#   /DC1/host/Folder1/cl1/Resources/rp1
		# Check the actual path retrieved from the VM host and the path with these entries removed
		my $resource_pool_path_fixed = $resource_pool_path;
		$resource_pool_path_fixed =~ s/\/host\//\//g;
		$resource_pool_path_fixed =~ s/\/Resources($|\/?)/$1/g;
		
		push @resource_pool_paths, $resource_pool_path_fixed;
		
		
		# If only 1 resource pool was found on the host, ignore the VM profile resource path setting and use the resource pool that was found
		if (scalar(@resource_pools) == 1) {
			notify($ERRORS{'DEBUG'}, 0, "single resource pool found on VM host $vmhost_name will be used: $resource_pool_path_fixed");
			$self->{resource_pool_view_object} = $resource_pool;
			return $resource_pool;
		}
		
		# Check if the retrieved resource pool matches the profile resource path
		if ($vmhost_profile_resource_path =~ /$resource_pool_path/i) {
			notify($ERRORS{'DEBUG'}, 0, "found matching resource pool on VM host $vmhost_name\n" .
					 "VM host profile resource path: $vmhost_profile_resource_path\n" .
					 "resource pool path on host: $resource_pool_path"
			);
			$self->{resource_pool_view_object} = $resource_pool;
			return $resource_pool;
		}
		
		# Check if the fixed retrieved resource pool path matches the profile resource path
		if ($vmhost_profile_resource_path =~ /^$resource_pool_path_fixed$/i) {
			notify($ERRORS{'DEBUG'}, 0, "found resource pool on VM host $vmhost_name matching VM host profile resource path with default hidden levels removed:\n" .
					 "path on VM host: '$resource_pool_path'\n" .
					 "modified path on VM host: '$resource_pool_path_fixed'\n" .
					 "VM profile path: '$vmhost_profile_resource_path'"
			);
			$self->{resource_pool_view_object} = $resource_pool;
			return $resource_pool;
		}
		
		# Check if this is a potential match - resource pool path retrieved from VM host begins or ends with the profile value
		if ($resource_pool_path_fixed =~ /^\/?$vmhost_profile_resource_path\//i) {
			notify($ERRORS{'DEBUG'}, 0, "resource pool on VM host $vmhost_name '$resource_pool_path_fixed' is a potential match, it begins with VM host profile resource path '$vmhost_profile_resource_path'");
			$potential_matches{$resource_pool_path_fixed} = $resource_pool;
		}
		elsif ($resource_pool_path_fixed =~ /\/$vmhost_profile_resource_path$/i) {
			notify($ERRORS{'DEBUG'}, 0, "resource pool on VM host $vmhost_name '$resource_pool_path_fixed' is a potential match, it ends with VM host profile resource path '$vmhost_profile_resource_path'");
			$potential_matches{$resource_pool_path_fixed} = $resource_pool;
		}
		else {
			#notify($ERRORS{'DEBUG'}, 0, "resource pool on VM host $vmhost_name does NOT match VM host profile resource path:\n" .
			#	"path on VM host: '$resource_pool_path'\n" .
			#	"VM profile path: '$vmhost_profile_resource_path'"
			#);
		}
	}
	
	# Check if a single potential match was found - if so, assume it should be used
	if (scalar(keys %potential_matches) == 1) {
		my $resource_pool_path = (keys %potential_matches)[0];
		my $resource_pool = $potential_matches{$resource_pool_path};
		$self->{resource_pool_view_object} = $resource_pool;
		notify($ERRORS{'DEBUG'}, 0, "single resource pool on VM host $vmhost_name which potentially matches VM host profile resource path will be used:\n" .
			"path on VM host: '$resource_pool_path'\n" .
			"VM profile path: '$vmhost_profile_resource_path'"
		);
		return $resource_pool;
	}
	
	# Resource pool was found
	if ($vmhost_profile_resource_path) {
		notify($ERRORS{'WARNING'}, 0, "resource path '$vmhost_profile_resource_path' configured in VM host profile does NOT match any of resource pool paths found on VM host $vmhost_name:\n" . join("\n", sort @resource_pool_paths));
	}
	elsif (scalar(@resource_pools) > 1) {
		notify($ERRORS{'WARNING'}, 0, "unable to determine correct resource pool to use, VM host $vmhost_name contains multiple resource pool paths, VM host profile resource path MUST be configured to one of the following values:\n" . join("\n", sort @resource_pool_paths));
	}
	else {
		notify($ERRORS{'WARNING'}, 0, "failed to determine resource pool to use on VM host $vmhost_name:\n" . join("\n", sort @resource_pool_paths));
	}
	
	return;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_managed_object_path

 Parameters  : $mo_ref
 Returns     : string
 Description : Constructs a path string from the root of a vCenter or standalone
               host to the managed object specified by the $mo_ref argument.
               Example, if the tree structure in vCenter is:
               DC1
               |---Folder1
                  |---ClusterA
                     |---ResourcePool5
                        |---vm100
               
               The following string is returned:
               /DC1/host/Folder1/ClusterA/Resources/ResourcePool5/vm100
               
               Note: 'host' and 'Resources' are not displayed in the vSphere
               Client.

=cut

sub _get_managed_object_path {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	my $mo_ref_argument = shift;
	if (!$mo_ref_argument) {
		notify($ERRORS{'WARNING'}, 0, "managed object reference argument was not supplied");
		return;
	}
	elsif (!ref($mo_ref_argument)) {
		notify($ERRORS{'WARNING'}, 0, "managed object reference argument is not a reference");
		return;
	}
	elsif (!$mo_ref_argument->isa('ManagedObjectReference')) {
		if (defined($mo_ref_argument->{mo_ref})) {
			$mo_ref_argument = $mo_ref_argument->{mo_ref};
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "managed object reference argument is not a ManagedObjectReference object");
			return;
		}
	}
	
	my $type = $mo_ref_argument->{type};
	my $value = $mo_ref_argument->{value};
	
	my $view = Vim::get_view('mo_ref' => $mo_ref_argument) || return;
	my $name = $view->{name} || return;
	
	my $parent_mo_ref;
	if ($type eq 'VirtualMachine' && $view->{resourcePool}) {
		$parent_mo_ref = $view->{resourcePool};
	}
	elsif ($view->{parent}) {
		$parent_mo_ref = $view->{parent};
	}
	else {
		# No parent, found root of path
		return;
	}
	
	#notify($ERRORS{'DEBUG'}, 0, format_data($parent_mo_ref));	
	
	my $parent_type = $parent_mo_ref->{type};
	my $parent_value = $parent_mo_ref->{value};
	#notify($ERRORS{'DEBUG'}, 0, "'$name' ($type: $value) --> parent: ($parent_type: $parent_value)");	
	
	my $parent_path = $self->_get_managed_object_path($parent_mo_ref) || '';
	return "$parent_path/$name";
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_parent_managed_object_view

 Parameters  : $mo_ref, $parent_view_type
 Returns     : string
 Description : Finds a parent of the managed object of the one specified by the
               $mo_ref argument matching the $parent_view_type argument.
               Examples of $parent_view_type are 'Datacenter',
               'ClusterComputeResource', etc. This is useful if you have a VM or
               resource pool and need to retrieve the datacenter or cluster
               managed object which it belongs to.

=cut

sub _get_parent_managed_object_view {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the managed object reference argument
	# Check if a mo_ref was passed or a view
	# If a view was passed, get the mo_ref from it
	my $mo_ref_argument = shift;
	if (!$mo_ref_argument) {
		notify($ERRORS{'WARNING'}, 0, "managed object reference argument was not supplied");
		return;
	}
	elsif (!ref($mo_ref_argument)) {
		notify($ERRORS{'WARNING'}, 0, "managed object reference argument is not a reference");
		return;
	}
	elsif (!$mo_ref_argument->isa('ManagedObjectReference')) {
		if (defined($mo_ref_argument->{mo_ref})) {
			$mo_ref_argument = $mo_ref_argument->{mo_ref};
		}
		else {
			notify($ERRORS{'WARNING'}, 0, "managed object reference argument is not a ManagedObjectReference object");
			return;
		}
	}
	
	my $parent_type_argument = shift;
	if (!$parent_type_argument) {
		notify($ERRORS{'WARNING'}, 0, "parent type argument was not supplied");
		return;
	}
	
	# Retrieve a view for the mo_ref argument
	my $view = Vim::get_view('mo_ref' => $mo_ref_argument);
	if (!$view) {
		notify($ERRORS{'WARNING'}, 0, "failed to retrieve view for managed object reference argument:\n" . format_data($mo_ref_argument));
		return;
	}
	
	# Check if the view has a parent
	if ($view->{parent}) {
		my $parent_mo_ref = $view->{parent};
		my $parent_type = $parent_mo_ref->{type};
		my $parent_value = $parent_mo_ref->{value};
		
		# Check if the parent matches the type argument
		if ($parent_type eq $parent_type_argument) {
			#notify($ERRORS{'DEBUG'}, 0, "found parent view matching type '$parent_type_argument': $parent_value");
			
			my $parent_view = Vim::get_view('mo_ref' => $parent_mo_ref);
			return $parent_view;
		}
		else {
			# Parent type does not match the type argument, recursively search upward
			return $self->_get_parent_managed_object_view($parent_mo_ref, $parent_type_argument);
		}
	}
	else {
		# No parent, found root of path
		notify($ERRORS{'WARNING'}, 0, "failed to find parent object matching type '$parent_type_argument'");
		return;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_vm_view

 Parameters  : $vmx_file_path (optional)
 Returns     : 
 Description : 

=cut

sub _get_vm_view {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmx path argument and convert it to a datastore path
	my $vmx_path = shift || $self->get_vmx_file_path();
	$vmx_path = $self->_get_datastore_path($vmx_path);
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	my $vm_view;
	eval { $vm_view = Vim::find_entity_view(view_type => 'VirtualMachine', begin_entity => $self->_get_datacenter_view(), filter => {'config.files.vmPathName' => $vmx_path}); };
	if (!$vm_view) {
		notify($ERRORS{'WARNING'}, 0, "failed to retrieve view object for VM: $vmx_path");
		return;
	}
	
	$self->{vm_view_objects}{$vmx_path} = $vm_view;
	return $self->{vm_view_objects}{$vmx_path};
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_virtual_disk_manager_view

 Parameters  : 
 Returns     : vSphere SDK virtual disk manager view object
 Description : Retrieves a vSphere SDK virtual disk manager view object.

=cut

sub _get_virtual_disk_manager_view {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{virtual_disk_manager_object} if $self->{virtual_disk_manager_object};
	
	# Get a virtual disk manager object
	my $service_content = Vim::get_service_content() || return;
	if (!$service_content->{virtualDiskManager}) {
		notify($ERRORS{'WARNING'}, 0, "failed to retrieve virtual disk manager object, it is not available via the vSphere SDK");
		return;
	}
	
	my $virtual_disk_manager = Vim::get_view(mo_ref => $service_content->{virtualDiskManager});
	if ($virtual_disk_manager) {
		#notify($ERRORS{'DEBUG'}, 0, "retrieved virtual disk manager object:\n" . format_data($virtual_disk_manager));
	}
	else {
		notify($ERRORS{'WARNING'}, 0, "failed to retrieve virtual disk manager object");
		return;
	}
	
	$self->{virtual_disk_manager_object} = $virtual_disk_manager;
	return $self->{virtual_disk_manager_object};
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_file_manager_view

 Parameters  : 
 Returns     : vSphere SDK file manager view object
 Description : Retrieves a vSphere SDK file manager view object.

=cut

sub _get_file_manager_view {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	return $self->{file_manager_object} if $self->{file_manager_object};
	
	my $service_content = Vim::get_service_content() || return;
	my $file_manager = Vim::get_view(mo_ref => $service_content->{fileManager});
	if (!$file_manager) {
		notify($ERRORS{'WARNING'}, 0, "failed to retrieve file manager object");
		return;
	}
	
	$self->{file_manager_object} = $file_manager;
	return $self->{file_manager_object};
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_datastore_object

 Parameters  : $datastore_name
 Returns     : vSphere SDK datastore object
 Description : Retrieves a datastore object for the datastore specified by the
               datastore name argument.

=cut

sub _get_datastore_object {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the datastore name argument
	my $datastore_name_argument = shift;
	if (!$datastore_name_argument) {
		notify($ERRORS{'WARNING'}, 0, "datastore name argument was not specified");
		return;
	}
	
	return $self->{datastore_objects}{$datastore_name_argument} if ($self->{datastore_objects}{$datastore_name_argument});
	
	my $datacenter_view = $self->_get_datacenter_view();
	
	# Get an array containing datastore managed object references
	my @datastore_mo_refs = @{$datacenter_view->datastore};
	
	# Loop through the datastore managed object references
	# Get a datastore view, add the view's summary to the return hash
	my @datastore_names_found;
	for my $datastore_mo_ref (@datastore_mo_refs) {
		my $datastore = Vim::get_view(mo_ref => $datastore_mo_ref);
		my $datastore_name = $datastore->summary->name;
		$self->{datastore_objects}{$datastore_name} = $datastore;
	}
	
	return $self->{datastore_objects}{$datastore_name_argument} if ($self->{datastore_objects}{$datastore_name_argument});
	
	notify($ERRORS{'WARNING'}, 0, "failed to find datastore named $datastore_name_argument, datastore names found:\n" . join("\n", keys(%{$self->{datastore_objects}})));
	return;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _get_datastore_info

 Parameters  : none
 Returns     : hash reference
 Description : Finds all datastores on the ESX host and returns a hash reference
               containing the datastore information. The keys of the hash are
               the datastore names. Example:
               
               my $datastore_info = $self->_get_datastore_info();
               $datastore_info->{datastore1}{accessible} = '1'
               $datastore_info->{datastore1}{capacity} = '31138512896'
               $datastore_info->{datastore1}{datastore}{type} = 'Datastore'
               $datastore_info->{datastore1}{datastore}{value} = '4bcf0efe-c426acc4-c7e1-001a644d1cc0'
               $datastore_info->{datastore1}{freeSpace} = '30683430912'
               $datastore_info->{datastore1}{name} = 'datastore1'
               $datastore_info->{datastore1}{type} = 'VMFS'
               $datastore_info->{datastore1}{uncommitted} = '0'
               $datastore_info->{datastore1}{url} = '/vmfs/volumes/4bcf0efe-c426acc4-c7e1-001a644d1cc0'

=cut

sub _get_datastore_info {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# If the datastore info was previously retrieved, return the cached data unless an argument was specified
	my $no_cache = shift;
	return $self->{datastore_info} if (!$no_cache && $self->{datastore_info});
	
	my $vmhost_hostname = $self->data->get_vmhost_hostname();
	
	my $datacenter_view = $self->_get_datacenter_view() || return;
	
	# Get an array containing datastore managed object references
	my @datastore_mo_refs = @{$datacenter_view->datastore};
	
	# Loop through the datastore managed object references
	# Get a datastore view, add the view's summary to the return hash
	my $datastore_info;
	for my $datastore_mo_ref (@datastore_mo_refs) {
		my $datastore_view = Vim::get_view(mo_ref => $datastore_mo_ref);
		my $datastore_name = $datastore_view->summary->name;
		
		# Make sure the datastore is accessible
		# Don't return info for inaccessible datastores
		my $datastore_accessible = $datastore_view->summary->accessible;
		if (!$datastore_accessible) {
			notify($ERRORS{'WARNING'}, 0, "datastore '$datastore_name' is mounted on $vmhost_hostname but not accessible");
			next;
		}
		
		my $datastore_url = $datastore_view->summary->url;
		if (!$datastore_url) {
			notify($ERRORS{'WARNING'}, 0, "unable to retrieve URL for datastore '$datastore_name'");
			next;
		}
		
		if ($datastore_url =~ /^(\/vmfs\/volumes|\w+fs|ds:)/i) {
			$datastore_view->summary->{normal_path} = "/vmfs/volumes/$datastore_name";
		}
		else {
			$datastore_view->summary->{normal_path} = $datastore_url;
		}
		
		$datastore_info->{$datastore_name} = $datastore_view->summary;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "retrieved datastore info:\n" . format_data($datastore_info));
	$self->{datastore_info} = $datastore_info;
	return $datastore_info;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 create_snapshot

 Parameters  : $vmx_file_path, $name (optional)
 Returns     : boolean
 Description : Creates a snapshot of the VM.

=cut

sub create_snapshot {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmx path argument and convert it to a datastore path
	my $vmx_path = $self->_get_datastore_path(shift) || return;
	
	my $snapshot_name = shift || ("VCL: " . convert_to_datetime());
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	my $vm = $self->_get_vm_view($vmx_path) || return;
	
	eval { $vm->CreateSnapshot(name => $snapshot_name,
										memory => 0,
										quiesce => 0,
										);
			};
	
	if ($@) {
		notify($ERRORS{'WARNING'}, 0, "failed to create snapshot of VM: $vmx_path, error:\n$@");
		return;
	}
	
	notify($ERRORS{'DEBUG'}, 0, "created snapshot '$snapshot_name' of VM: $vmx_path");
	return 1;
}

#/////////////////////////////////////////////////////////////////////////////

=head2 snapshot_exists

 Parameters  : $vmx_file_path
 Returns     : boolean
 Description : Determines if a snapshot exists for the VM.

=cut

sub snapshot_exists {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	# Get the vmx path argument and convert it to a datastore path
	my $vmx_path = $self->_get_datastore_path(shift) || return;
	
	# Override the die handler
	local $SIG{__DIE__} = sub{};
	
	my $vm = $self->_get_vm_view($vmx_path) || return;
	
	if (defined($vm->snapshot)) {
		notify($ERRORS{'DEBUG'}, 0, "snapshot exists for VM: $vmx_path\n" . format_data($vm->snapshot));
		return 1;
	}
	else {
		notify($ERRORS{'DEBUG'}, 0, "snapshot does NOT exist for VM: $vmx_path");
		return 0;
	}
}

#/////////////////////////////////////////////////////////////////////////////

=head2 _is_vcenter

 Parameters  : 
 Returns     : boolean
 Description : Determines if the VM host is vCenter or standalone.

=cut

sub _is_vcenter {
	my $self = shift;
	if (ref($self) !~ /VCL::Module/i) {
		notify($ERRORS{'CRITICAL'}, 0, "subroutine was called as a function, it must be called as a class method");
		return;
	}
	
	my $service_content = Vim::get_service_content();
	my $api_type = $service_content->{about}->{apiType};
	
	# apiType should either be:
	#    'VirtualCenter' - VirtualCenter instance
	#    'HostAgent' - standalone ESX/ESXi or VMware Server host
	
	return ($api_type =~ /VirtualCenter/) ? 1 : 0;
}

#/////////////////////////////////////////////////////////////////////////////

1;
__END__

=head1 SEE ALSO

L<http://cwiki.apache.org/VCL/>

=cut
