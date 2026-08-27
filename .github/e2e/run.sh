#!/usr/bin/env bash
# End-to-end validation of SPEC.md rules 1-12 against a live stack.
# Assumes: MariaDB reachable at $DBHOST:$DBPORT root/'' with schemas loaded,
# cwd = repository root. Starts PHP dev server itself; one continuous run.
set -u
DBHOST="${DBHOST:-127.0.0.1}"; DBPORT="${DBPORT:-3306}"
PORT="${PORT:-8089}"
BASE="http://127.0.0.1:${PORT}/index.php"
SETUP_URL="http://127.0.0.1:${PORT}/setup.php"
WORK="$(mktemp -d)"
PASS=0; FAIL=0

mysqlq() { mysql -h"$DBHOST" -P"$DBPORT" -uroot -N -B -e "$1"; }
ck() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS: $1"; else FAIL=$((FAIL+1)); echo "FAIL: $1"; fi; }

(php -S "127.0.0.1:${PORT}" -t web >"$WORK/php.log" 2>&1 &)
for i in $(seq 1 30); do curl -s -o /dev/null "$BASE" && break; sleep 0.5; done

AJ=$WORK/admin.jar; BJ=$WORK/bob.jar; CJ=$WORK/carol.jar; LJ=$WORK/alice.jar
get() { curl -sL -b "$1" -c "$1" "${2:-$BASE}"; }                       # GET page w/ session
tok() { get "$1" "${2:-$BASE}" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1; }
act() { # act <jar> <post-data> [outfile] -> final http code after redirects
  curl -sL -b "$1" -c "$1" --data "$2" -o "${3:-$WORK/out.html}" -w '%{http_code}' "$BASE"; }
act_raw() { curl -s -b "$1" -c "$1" --data "$2" -o /dev/null -w '%{http_code}|%{redirect_url}' "$BASE"; }
new_thread_id() { # echo thread id created via create_thread post data (no file fields)
  local jar=$1 data=$2; local r; r=$(curl -s -b "$jar" -c "$jar" --data "$data" -o /dev/null -w '%{redirect_url}' "$BASE");
  printf '%s' "$r" | grep -oP 'id=\K[0-9]+' | head -1; }
pidof_thread() { curl -s -b "$AJ" -c "$AJ" "$BASE?page=thread_page&id=$1" > "$WORK/tp.html"; grep -oP 'name="proposal_id" value="\K[0-9]+' "$WORK/tp.html" | head -1; }
uid() { mysqlq "SELECT id FROM astronomical_db.users WHERE username='$1'"; }
STARS=$(mysqlq "SELECT id FROM astronomical_db.categories WHERE slug='stars'")
GALAXY=$(mysqlq "SELECT id FROM astronomical_db.categories WHERE slug='galaxies'")
M31=$(mysqlq "SELECT id FROM catalogue_db.objects WHERE catalog_id='M31'")

echo "== Rule 1: admin approves registrations =="
T=$(tok $AJ "$SETUP_URL")
act_setup() { curl -sL -b $AJ -c $AJ --data "$1" -o "$2" -w '%{http_code}' "$SETUP_URL"; }
CODE=$(act_setup "csrf_token=$T&username=rootadmin&password=adminpass123" $WORK/setup.html)
ck "setup.php creates first administrator" $([ "$CODE" = 200 ] && grep -q 'Administrator created' $WORK/setup.html && echo 0 || echo 1)
T=$(tok $AJ "$SETUP_URL")
act_setup "csrf_token=$T&username=sneakyadmin&password=adminpass123" $WORK/setup2.html >/dev/null
ck "setup.php refuses a second administrator" $(grep -q 'already exists' $WORK/setup2.html && echo 0 || echo 1)
TA=$(tok $AJ "$BASE?page=login")
act $AJ "action=login&csrf_token=$TA&username=rootadmin&password=adminpass123" $WORK/lia-admin.html >/dev/null
ck "created administrator can log in" $(grep -qi 'welcome back, rootadmin' $WORK/lia-admin.html && echo 0 || echo 1)

for u in bob carol alice; do
  TJ=$WORK/$u.jar
  TU=$(tok $TJ "$BASE?page=register")
  act $TJ "action=register&csrf_token=$TU&username=$u&password=testpass99&password_confirmation=testpass99" $WORK/reg-$u.html >/dev/null
done
ck "3 registrations wait in pending state (R1)" $([ "$(mysqlq "SELECT COUNT(*) FROM astronomical_db.users WHERE registration_status='pending'")" -eq 3 ] && echo 0 || echo 1)

TB=$(tok $BJ "$BASE?page=login")
act $BJ "action=login&csrf_token=$TB&username=bob&password=testpass99" $WORK/lp.html >/dev/null
ck "pending user cannot log in yet" $(grep -q 'waiting for administrator approval' $WORK/lp.html && echo 0 || echo 1)

TA=$(tok $AJ "$BASE?page=admin")
for u in bob carol alice; do act $AJ "action=approve_registration&csrf_token=$TA&user_id=$(uid $u)" >/dev/null; done
ck "approved members are active (R1)" $([ "$(mysqlq "SELECT COUNT(*) FROM astronomical_db.users WHERE role='member' AND registration_status='active'")" -eq 3 ] && echo 0 || echo 1)
TLA=$(tok $LJ "$BASE?page=login")
act $LJ "action=login&csrf_token=$TLA&username=alice&password=testpass99" $WORK/lia.html >/dev/null
ck "approved member logs in successfully" $(grep -qi 'welcome back, alice' $WORK/lia.html && echo 0 || echo 1)
TBB=$(tok $BJ "$BASE?page=login")
act $BJ "action=login&csrf_token=$TBB&username=bob&password=testpass99" >/dev/null
TCC=$(tok $CJ "$BASE?page=login")
act $CJ "action=login&csrf_token=$TCC&username=carol&password=testpass99" >/dev/null

TB=$(tok $BJ "$BASE?page=login")
BADL=$(curl -s -b $BJ -c $BJ --data "action=login&csrf_token=TOTALLYWRONGTOKEN&username=bob&password=testpass99" -o /dev/null -w '%{http_code}' "$BASE")
ck "forged CSRF token rejected with 419" $([ "$BADL" = 419 ] && echo 0 || echo 1)
NOTOK=$(curl -s -b $BJ -c $BJ --data "action=login&username=bob&password=testpass99" -o /dev/null -w '%{http_code}' "$BASE")
ck "absent CSRF token rejected with 419" $([ "$NOTOK" = 419 ] && echo 0 || echo 1)

echo "== Rules 4+5+9: proposals, expert approval, linking replies =="
mk_add() { # jar name -> thread id (proposal authored by jar's user)
  local jar=$1 name=$2 TP TT;
  TP=$(tok $jar "$BASE?page=new_thread&category=stars")
  new_thread_id "$jar" "action=create_thread&csrf_token=$TP&type=proposal&proposal_kind=add_entry&category_id=$STARS&title=Add+$name&body=Please+add+$name+to+the+catalogue.&name=$name&object_type=star"
}
T1=$(mk_add $BJ StarBX1); T2=$(mk_add $BJ StarBX2); T3=$(mk_add $BJ StarBX3)
ck "three add_entry proposal threads created ($T1,$T2,$T3)" $([ -n "$T1" ] && [ -n "$T2" ] && [ -n "$T3" ] && echo 0 || echo 1)
P1=$(pidof_thread $T1)
ck "expert controls rendered on pending proposal" $([ -n "$P1" ] && grep -q 'Approve' "$WORK/tp.html" && echo 0 || echo 1)

TCJ=$WORK/carol.jar
TPC=$(tok $TCJ "$BASE?page=new_thread&category=stars")
TO=$(new_thread_id "$TCJ" "action=create_thread&csrf_token=$TPC&type=proposal&proposal_kind=add_entry&category_id=$STARS&title=CarolSelfApprove&body=x&name=CarolSelfStar&object_type=star")
PO=$(pidof_thread $TO)
SC=$(act $TCJ "action=approve_proposal&csrf_token=$TPC&proposal_id=$PO" $WORK/self.html)
ck "author cannot approve own proposal (R5)" $(grep -q 'cannot approve your own proposal' $WORK/self.html && [ "$(mysqlq "SELECT status FROM astronomical_db.proposals WHERE id=$PO")" = pending ] && echo 0 || echo 1)

STA=$(tok $AJ "$BASE?page=admin")
approve() { TAA=$(tok $AJ "$BASE?page=thread_page&id=$2"); act $AJ "action=approve_proposal&csrf_token=$TAA&proposal_id=$1" "$3"; }
approve $P1 $T1 $WORK/ap1.html
ck "approval applied entry to catalogue database (R5/R12)" $([ "$(mysqlq "SELECT COUNT(*) FROM catalogue_db.objects WHERE name='StarBX1'")" -eq 1 ] && echo 0 || echo 1)
get $AJ "$BASE?page=thread_page&id=$T1" > $WORK/t1.html
ck "approval reply links the catalogue entry chip (R9)" $(grep -q 'Catalogue:' $WORK/t1.html && echo 0 || echo 1)
approve "$(pidof_thread $T2)" $T2 $WORK/ap2.html
approve "$(pidof_thread $T3)" $T3 $WORK/ap3.html
BSTATE=$(mysqlq "SELECT CONCAT(expertise,'/',IFNULL(promotion_source,'-')) FROM astronomical_db.users WHERE username='bob'")
ck "author auto-promoted to expert after 3 approvals (R5): '$BSTATE'" $([ "$BSTATE" = "expert/auto" ] && echo 0 || echo 1)
ck "creation audit rows recorded" $([ "$(mysqlq "SELECT COUNT(*) FROM astronomical_db.object_edits WHERE field='__created__'")" -eq 3 ] && echo 0 || echo 1)

TDUP=$(mk_add $BJ DupStarZ); PDUP=$(pidof_thread $TDUP)
TAR=$(tok $AJ "$BASE?page=thread_page&id=$TDUP")
act $AJ "action=reject_proposal&csrf_token=$TAR&proposal_id=$PDUP&reason=Duplicate+of+HR+2491" $WORK/rej.html >/dev/null
ck "rejection persisted with mandatory reason" $([ "$(mysqlq "SELECT status FROM astronomical_db.proposals WHERE id=$PDUP")" = rejected ] && [ -n "$(mysqlq "SELECT reason FROM astronomical_db.proposals WHERE id=$PDUP")" ] && echo 0 || echo 1)
get $AJ "$BASE?page=thread_page&id=$TDUP" > $WORK/rejt.html
ck "rejection reason posted as reply message (R9)" $(grep -q 'Proposal rejected: Duplicate of HR 2491' $WORK/rejt.html && echo 0 || echo 1)

NOSUB=$(curl -s -b $BJ -c $BJ --data "action=create_thread&csrf_token=$(tok $BJ "$BASE?page=new_thread&category=stars")&type=proposal&proposal_kind=add_entry&category_id=$STARS&title=WiredGalaxy&body=x&name=XGal&object_type=galaxy" -o $WORK/sub.html -w '%{http_code}' "$BASE" >/dev/null; grep -q 'belong in its subforum' $WORK/sub.html && echo ok || echo no)
ck "object-type mismatch blocked within subforum (R9)" $([ "$NOSUB" = ok ] && echo 0 || echo 1)
BT=$(curl -s -b $BJ -c $BJ --data "action=create_thread&csrf_token=$(tok $BJ "$BASE?page=new_thread&category=general")&type=bogus_kind&category_id=$GENERALID&title=t&body=b" -o /dev/null -w '%{http_code}' "$BASE")
GENERALID=$(mysqlq "SELECT id FROM astronomical_db.categories WHERE slug='general'")
BT=$(curl -s -b $BJ -c $BJ --data "action=create_thread&csrf_token=$(tok $BJ "$BASE?page=new_thread&category=general")&type=bogus_kind&category_id=$GENERALID&title=t&body=b" -o /dev/null -w '%{http_code}' "$BASE")
ck "bogus thread type falls back safely (R2)" $([ "$BT" = 302 ] && echo 0 || echo 1)

echo "== Rule 3: identification confirmations =="
TI=$( { TT=$(tok $CJ "$BASE?page=new_thread&category=stars"); new_thread_id "$CJ" "action=create_thread&csrf_token=$TT&type=identification&category_id=$STARS&title=Unknown+faint+dot&body=Spotted+near+Orion,+help?"; } )
TRB=$(tok $BJ "$BASE?page=thread_page&id=$TI")
act $BJ "action=reply&csrf_token=$TRB&thread_id=$TI&body=Looks+like+Andromeda+to+me." >/dev/null
TCI=$(tok $CJ "$BASE?page=thread_page&id=$TI")
act $CJ "action=confirm_identification&csrf_token=$TCI&thread_id=$TI&object_id=$M31" >/dev/null
ck "author confirms identification from opening context (R3)" $([ "$(mysqlq "SELECT identified_object_id FROM astronomical_db.threads WHERE id=$TI")" = "$M31" ] && [ "$(mysqlq "SELECT is_solution FROM astronomical_db.posts WHERE thread_id=$TI AND is_opening=1")" = 1 ] && echo 0 || echo 1)
TBI=$(tok $BJ "$BASE?page=thread_page&id=$TI")
act $BJ "action=confirm_identification&csrf_token=$TBI&thread_id=$TI&object_id=$M31" $WORK/idb.html >/dev/null
ck "non-author cannot confirm identification (R3)" $(grep -q 'Only the thread author' $WORK/idb.html && echo 0 || echo 1)

echo "== Rules 6+7: disputes revert; experts demoted =="
TED=$( { TT=$(tok $CJ "$BASE?page=new_thread&category=stars"); new_thread_id "$CJ" "action=create_thread&csrf_token=$TT&type=proposal&proposal_kind=edit_field&category_id=$STARS&title=Fix+distance&body=Better+distance&target_object_id=$M31&field=distance_ly&new_value=2500000"; } )
PED=$(pidof_thread $TED)
TBE=$(tok $BJ "$BASE?page=thread_page&id=$TED")
act $BJ "action=approve_proposal&csrf_token=$TBE&proposal_id=$PED" >/dev/null
NEWV=$(mysqlq "SELECT distance_ly FROM catalogue_db.objects WHERE id=$M31")
OLDV=$(mysqlq "SELECT old_value FROM astronomical_db.object_edits WHERE proposal_id=$PED LIMIT 1")
ck "expert applied edit_field to catalogue (R5): '$OLDV'->'$NEWV'" $([ "$NEWV" = "2500000.000" ] && echo 0 || echo 1)

TFC=$(tok $LJ "$BASE?page=thread_page&id=$TED")
act $LJ "action=file_dispute&csrf_token=$TFC&proposal_id=$PED&reason=Gaia+parallax+disagrees" >/dev/null
DID=$(mysqlq "SELECT id FROM astronomical_db.disputes WHERE proposal_id=$PED LIMIT 1")
TBR=$(tok $BJ "$BASE?page=thread_page&id=$TED")
act $BJ "action=resolve_dispute&csrf_token=$TBR&dispute_id=$DID&resolution=uphold" $WORK/denied.html >/dev/null
ck "original approver blocked from resolving dispute (R6)" $([ "$(mysqlq "SELECT status FROM astronomical_db.disputes WHERE id=$DID")" = pending ] && echo 0 || echo 1)
TLR=$(tok $LJ "$BASE?page=thread_page&id=$TED")
act $LJ "action=resolve_dispute&csrf_token=$TLR&dispute_id=$DID&resolution=uphold" $WORK/sres.html >/dev/null
ck "disputant cannot resolve own dispute (R6)" $([ "$(mysqlq "SELECT status FROM astronomical_db.disputes WHERE id=$DID")" = pending ] && echo 0 || echo 1)
TAU=$(tok $AJ "$BASE?page=thread_page&id=$TED")
act $AJ "action=resolve_dispute&csrf_token=$TAU&dispute_id=$DID&resolution=uphold" >/dev/null
REV=$(mysqlq "SELECT distance_ly FROM catalogue_db.objects WHERE id=$M31")
ck "upheld dispute reverted value to last good (R6): '$REV'" $([ "$REV" = "$OLDV" ] && echo 0 || echo 1)
ck "proposal status flipped to reverted" $([ "$(mysqlq "SELECT status FROM astronomical_db.proposals WHERE id=$PED")" = reverted ] && echo 0 || echo 1)
ck "audit row marked reverted" $([ "$(mysqlq "SELECT reverted FROM astronomical_db.object_edits WHERE proposal_id=$PED")" = 1 ] && echo 0 || echo 1)

mk_edit_by_bob() { # two reverted proposals authored by BOB (whose standing we track)
  local title=$1 field=$2 val=$3 TT ID;
  TT=$(tok $BJ "$BASE?page=new_thread&category=stars")
  ID=$(new_thread_id "$BJ" "action=create_thread&csrf_token=$TT&type=proposal&proposal_kind=edit_field&category_id=$STARS&title=$title&body=x&target_object_id=$M31&field=$field&new_value=$val")
  P=$(pidof_thread $ID)
  approve "$P" "$ID" $WORK/abx.html
  TCX=$(tok $CJ "$BASE?page=thread_page&id=$ID")
  act $CJ "action=file_dispute&csrf_token=$TCX&proposal_id=$P&reason=Disputed+$title" >/dev/null
  DX=$(mysqlq "SELECT id FROM astronomical_db.disputes WHERE proposal_id=$P LIMIT 1")
  TAX=$(tok $AJ "$BASE?page=thread_page&id=$ID")
  act $AJ "action=resolve_dispute&csrf_token=$TAX&dispute_id=$DX&resolution=uphold" >/dev/null
}
mk_edit_by_bob BobNotesEdit1 notes wrongnoteone
CK1=$(mysqlq "SELECT expertise FROM astronomical_db.users WHERE username='bob'")
ck "first revert does not yet strip expert (R7 threshold)" $([ "$CK1" = expert ] && echo 0 || echo 1)
mk_edit_by_bob BobNotesEdit2 notes wrongnotetwo
BSTATE2=$(mysqlq "SELECT CONCAT(expertise,'/',IFNULL(promotion_source,'-')) FROM astronomical_db.users WHERE username='bob'")
ck "second upheld revert strips auto-expert (R7): '$BSTATE2'" $([ "$BSTATE2" = "normal/-" ] && echo 0 || echo 1)

echo "== Rule 8: verification and restriction =="
CID=$(uid carol)
TA=$(tok $AJ "$BASE?page=admin")
act $AJ "action=verify&csrf_token=$TA&user_id=$CID&note=Ten+years+on+the+AAVSO+roster" >/dev/null
VS=$(mysqlq "SELECT CONCAT(expertise,'/',promotion_source) FROM astronomical_db.users WHERE username='carol'")
NVFY=$(mysqlq "SELECT COUNT(*) FROM astronomical_db.verifications WHERE user_id=$CID AND kind='verify'")
ck "admin verified member with recorded why-note (R8)" $([ "$VS" = "verified/admin" ] && [ "$NVFY" -ge 1 ] && echo 0 || echo 1)
VL=$(curl -s -b $LJ -o /dev/null -w '%{http_code}' "$BASE?page=verification_log&id=$CID")
ck "verification log forbidden to ordinary members (403)" $([ "$VL" = 403 ] && echo 0 || echo 1)
get $AJ "$BASE?page=verification_log&id=$CID" > $WORK/vlog.html
ck "admins can see why a user was verified (R8)" $(grep -q 'AAVSO roster' $WORK/vlog.html && echo 0 || echo 1)
TA=$(tok $AJ "$BASE?page=admin")
act $AJ "action=restrict&csrf_token=$TA&user_id=$CID&note=Repeated+bad+data" >/dev/null
RF=$(mysqlq "SELECT is_restricted FROM astronomical_db.users WHERE username='carol'")
TCD=$(tok $CJ "$BASE?page=thread_page&id=$TI")
act $CJ "action=reply&csrf_token=$TCD&thread_id=$TI&body=still+here?" $WORK/crb.html >/dev/null
ck "restricted verified member loses write access (R8)" $([ "$RF" = 1 ] && grep -q 'restricted and cannot contribute' $WORK/crb.html && echo 0 || echo 1)
TA=$(tok $AJ "$BASE?page=admin")
act $AJ "action=unrestrict&csrf_token=$TA&user_id=$CID&note=Apology+accepted" >/dev/null
ck "unrestriction clears the flag, keeping verified rank" $([ "$(mysqlq "SELECT CONCAT(is_restricted,'/',expertise) FROM astronomical_db.users WHERE username='carol'")" = "0/verified" ] && echo 0 || echo 1)

echo "== Rules 11+12: pictures flow onto catalogue entries =="
printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' | base64 -d > "$WORK/tiny.png"
TTG=$(tok $BJ "$BASE?page=new_thread&category=galaxies")
TGAL=$(curl -s -b $BJ -c $BJ \
  -F "action=create_thread" -F "csrf_token=$TTG" \
  -F "category_id=$GALAXY" -F "type=proposal" -F "proposal_kind=add_entry" \
  -F "title=Galaxy GB-9" -F "body=with picture" \
  -F "name=GalaxyGB9" -F "object_type=galaxy" \
  -F 'images[]=@'"$WORK"'/tiny.png;type=image/png' \
  -o /dev/null -w '%{redirect_url}' "$BASE" | grep -oP 'id=\K[0-9]+')
PGAL=$(pidof_thread $TGAL)
PICT=$(grep -c 'uploads/img_' "$WORK/tp.html" || true)
ck "picture shown inside proposal pre-approval (R11)" $([ "${PICT:-0}" -ge 1 ] && echo 0 || echo 1)
approve $PGAL $TGAL $WORK/gap.html
ATT=$(mysqlq "SELECT COUNT(*) FROM catalogue_db.object_images WHERE proposal_id=$PGAL AND object_id IS NOT NULL")
ck "approval attached picture to catalogue row (R11/R12)" $([ "$ATT" -eq 1 ] && echo 0 || echo 1)
CATIMG=$(get $AJ "$BASE?page=catalogue" | grep -c 'uploads/img_' || true)
DETIMG=$(get $AJ "$BASE?page=object_detail&id=$M31" | grep -ci 'observer\|img_' || true)
ck "pictures render across catalogue pages" $([ "${CATIMG:-0}" -ge 1 ] && [ "${DETIMG:-0}" -ge 1 ] && echo 0 || echo 1)

echo "== Rules 10 + pages =="
BOBID=$(uid bob)
get $AJ "$BASE?page=profile&id=$BOBID" > $WORK/prof.html
ck "profile exposes standing stats + history (R10)" $(grep -q 'Proposals filed' $WORK/prof.html && grep -q 'Reviews performed' $WORK/prof.html && echo 0 || echo 1)
get $LJ "$BASE?page=forums" > $WORK/forums.html
ck "subforum index lists object-type categories (R9)" $(grep -q 'Subforum for star entries\|GENERAL' $WORK/forums.html && echo 0 || echo 1)
get $AJ "$BASE?page=catalogue&q=Sirius" > $WORK/srch.html
ck "catalogue search narrows results" $(grep -q '>Sirius<' $WORK/srch.html && ! grep -q '>Andromeda<' $WORK/srch.html && echo 0 || echo 1)
UNKNOWN=$(curl -s -o $WORK/nf.html -w '%{http_code}' "$BASE?page=does_not_exist")
ck "unknown page falls back safely (HTTP $UNKNOWN)" $([ "$UNKNOWN" = 200 ] && grep -q 'Identify the sky' $WORK/nf.html && echo 0 || echo 1)

echo ""
echo "================ RESULTS: $PASS passed, $FAIL failed ================"
tail -5 "$WORK/php.log" 2>/dev/null || true
exit $([ $FAIL -eq 0 ] && echo 0 || echo 1)
