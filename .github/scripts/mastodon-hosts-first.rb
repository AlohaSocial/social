# SPDX-FileCopyrightText: 2026 Aloha Social contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Loaded into the interop Mastodon containers with RUBYOPT. Mastodon's HTTP
# client resolves with Resolv::DNS alone, so the runner's /etc/hosts entries
# for nextcloud.test and mastodon.test reach it only through the runner's DNS
# stub, which can stop answering in time. Names listed in /etc/hosts are
# answered from it; every other name is still resolved over DNS.

require 'resolv'

class Resolv
  class DNS
    alias interop_dns_getaddresses getaddresses

    def getaddresses(name)
      hosts = Resolv::Hosts.new.getaddresses(name.to_s)
      return interop_dns_getaddresses(name) if hosts.empty?

      hosts.map { |address| address.include?(':') ? Resolv::IPv6.create(address) : Resolv::IPv4.create(address) }
    end
  end
end
