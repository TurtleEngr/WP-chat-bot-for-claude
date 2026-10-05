# Makefile for WP-claude-chat-bot

# ----------
# Macros

SHELL := /bin/bash

mProj = WP-claude-chat-bot
mProduct = dist/claude-chat-bot-VERSION.zip

mBuildList = \
	dist/claude-chat-bot \
	dist/claude-chat-bot/css \
	dist/claude-chat-bot/js \
	dist/claude-chat-bot/claude.php \
	dist/claude-chat-bot/claude3.png \
	dist/claude-chat-bot/claude_set.png \
	dist/claude-chat-bot/readme.txt \
	dist/claude-chat-bot/LICENSE

mServer = moria.whyayh.com
mPubDev = /rel/development/software/own/$(mProj)
mPubRel = /rel/released/software/own/$(mProj)

# ----------
# Main Targets

usage :
	@echo "Usage:"
	@echo "build - build dist/ with dirs and files to be installed"
	@echo "incPatch, incMinor, or incMajor - before save or publish"
	@echo "    Update Changelog in readme.txt"
	@echo "save - create plugin install zip file, and cp to development"
	@echo "publish - copy zip files to release area"
	@echo "clean - rm tmp files"
	@echo "dist-clean - clean and remove dist dir"
	@echo
	@echo "VERSION VERSION-dev VERSION-rel"
	@echo $$(cat VERSION*)
	@git st

update :
	git co develop
	git pull origin develop

build : clean update README.md $(mProduct)
	@echo 'If OK, make save'

save development : check-dev
	-git ci -am Updated
	git push origin develop
	-ssh $(mServer) mkdir -p $(mPubDev)
	rsync -a README.org readme.txt dist/claude-chat-bot-$$(cat VERSION).zip $(mServer):$(mPubDev)
	cp VERSION VERSION-dev
	-git ci -am Updated
	git push origin develop
	@echo 'If OK, make publish'

publish release : check-rel
	-git ci -am Updated
	git tag -f "ver-$$(cat VERSION)"
	git push --tags origin develop
	git co main
	git pull --tags origin main
	git merge develop
	git push --tags origin main
	git co develop
	-ssh $(mServer) mkdir -p $(mPubRel)
	rsync -a README.org readme.txt dist/claude-chat-bot-$$(cat VERSION).zip $(mServer):$(mPubRel)
	cp VERSION VERSION-rel
	-git ci -am Updated
	git push origin develop
	@echo 'If done, make dist-clean'

clean :
	-find . -type f -name '*~' -exec rm {} \;
	-rm -rf dist

dist-clean : clean
	-rm -rf dist tmp

# ----------
# Work Targets

$(mProduct) : $(mBuildList)
	php -l claude.php
	cd dist; zip -r claude-chat-bot-$$(cat ../VERSION).zip claude-chat-bot
	-touch $@

README.md : README.org VERSION
	pandoc -f org -t markdown <README.org >$@
	sed -i "s/VERSION/$$(cat VERSION)/" $@
	sed -i 's/^\[version]/![version]/' $@
	sed -i 's/^\[WordPress]/![WordPress]/' $@

check-dev :
	if diff -q VERSION VERSION-dev >/dev/null 2>&1; then \
		echo "Development versions must be different."; \
		echo "increment and rebuild."; \
		exit 1; \
	fi

check-rel :
	if diff -q VERSION VERSION-rel >/dev/null 2>&1; then \
		echo "Released versions must be different."; \
		echo "increment and rebuild."; \
		exit 1; \
	fi

# ----------
# Single Targets

VERSION :
	echo '0.0.0' >$@

incPatch : VERSION
	incver.sh -p

incMinor : VERSION
	incver.sh -m

incMajor : VERSION
	incver.sh -M

dist/claude-chat-bot :
	-mkdir -p $@

dist/claude-chat-bot/css : css
	rsync -r $? dist/claude-chat-bot/

dist/claude-chat-bot/js : js
	rsync -r $? dist/claude-chat-bot/

dist/claude-chat-bot/claude.php : claude.php
	sed "s/VERSION/$$(cat VERSION)/" <$? >$@

dist/claude-chat-bot/readme.txt : readme.txt
	sed "s/VERSION/$$(cat VERSION)/" <$? >$@

dist/claude-chat-bot/claude3.png : claude3.png
	cp $? $@

dist/claude-chat-bot/claude_set.png : claude_set.png
	cp $? $@

dist/claude-chat-bot/LICENSE : LICENSE
	cp $? $@
