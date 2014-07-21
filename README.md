EmailNewUser
============

Processwire module for emailing a new user their account details, and optionally automatically generate a password for them.

The following things are configurable:

* From email address (if left blank it will use the admin email set in the config/php file
* Email subject
* Email body - includes the ability to use any fields from the user template using {field_name} codes so these can be put into the body wherever you want.
* Whether to automatically generate a password. This can be toggled on/off, but even if it is on, you can override it by simply entering a password manually when creating the new user.

Because it is generally not a good idea to email passwords, it is highly recommended to use the new Password Force Change module in conjunction with this module. That way the user has to change their password the first time they login which will somewhat reduce the vulnerability of emailing the password.

Please let me know if you have any ideas for improvements.

###Support
https://processwire.com/talk/topic/7051-email-new-user/

## License

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

(See included LICENSE file for full license text.)