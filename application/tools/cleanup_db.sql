delete from libgenrelist where not exists (select 1 from libgenre where libgenrelist.genreid = libgenre.genreid);
delete from libseqname where not exists ( select 1 from libseq where libseqname.seqid = libseq.seqid);
delete from libavtorname where not exists (select 1 from libavtor where libavtorname.avtorid = libavtor.avtorid)
and libavtorname.masterid = 0
and not exists (select 1 from libavtoraliase where libavtorname.avtorid in (libavtoraliase.badid, libavtoraliase.goodid))
and not exists (select 1 from libtranslator where libavtorname.avtorid = libtranslator.translatorid)
and not exists (select 1 from libavtorname child where child.masterid = libavtorname.avtorid);
